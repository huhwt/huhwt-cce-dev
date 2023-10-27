<?php

/**
 * webtrees - clippings cart enhanced
 *
 * Copyright (C) 2023 huhwt. All rights reserved.
 * Copyright (C) 2021 Hermann Hartenthaler. All rights reserved.
 * Copyright (C) 2021 Richard Cissée. All rights reserved.
 *
 * webtrees: online genealogy / web based family history software
 * Copyright (C) 2021 webtrees development team.
 *
 */

declare(strict_types=1);

namespace HuHwt\WebtreesMods\ClippingsCartEnhanced\Traits;

use Fisharebest\Webtrees\Auth;

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Location;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Repository;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Submitter;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\Webtrees;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use HuHwt\WebtreesMods\ClippingsCartEnhanced\AllConnected;
use HuHwt\WebtreesMods\ClippingsCartEnhanced\AncestorCircles;
use HuHwt\WebtreesMods\ClippingsCartEnhanced\CompleteGED;
use HuHwt\WebtreesMods\ClippingsCartEnhanced\PartnerChains;
use HuHwt\WebtreesMods\ClippingsCartEnhanced\PartnerChainsGlobal;

/**
 * Trait CCEaddActions - bundling all add-Actions related to enhanced clipping
 */
trait CCEaddActions
{
    // What to add to the cart?
    // HH.mod - additional actions --
    private const ADD_PARTNER_CHAINS     = 'add partner chains for this individual or a family';
    private const ADD_ALL_PARTNER_CHAINS = 'all partner chains in this tree';
    private const ADD_ALL_CIRCLES        = 'all circles - clean version';
    private const ADD_ALL_LINKED_PERSONS = 'all connected persons in this family tree - Caution: probably very high number of persons!';
    private const ADD_COMPLETE_GED       = 'all persons in this family tree - Caution: probably very high number of persons!';

    /**
     * GET and POST actions
     */

#region     GET and POST

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getAddFamilyAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        assert($tree instanceof Tree);

        $xref = Validator::queryParams($request)->isXref()->string('xref');

        $family = Registry::familyFactory()->make($xref, $tree);
        $family = Auth::checkFamilyAccess($family);
        $name   = $family->fullName();

        $options = [
            self::ADD_RECORD_ONLY => $name,
            /* I18N: %s is a family (husband + wife) */
            self::ADD_CHILDREN    => I18N::translate('%s and their children', $name),
            /* I18N: %s is a family (husband + wife) */
            self::ADD_DESCENDANTS => I18N::translate('%s and their descendants', $name),
            /* I18N: %s is a family (husband + wife) */
            self::ADD_PARTNER_CHAINS => I18N::translate('%s and the partner chains they belong to', $name),
        ];

        /* I18N: %s is a family (husband + wife) */
        $title = I18N::translate('Add %s to the clippings cart', $name);

        return $this->viewResponse($this->name() . '::' . 'add-options', [
            'options'       => $options,
            'record'        => $family,
            'title'         => $title,
            'tree'          => $tree,
            'stylesheet'    => $this->assetUrl('css/cce.css'),
            'javascript'    => $this->assetUrl('js/cce.js'),
        ]);
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function postAddFamilyAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        $xref   = Validator::parsedBody($request)->string('xref');
        $option = Validator::parsedBody($request)->string('option');

        $family = Registry::familyFactory()->make($xref, $tree);
        $family = Auth::checkFamilyAccess($family);

        $_dname = 'wtVIZ-DATA~FAM_' . $xref;
        $this->putVIZdname($_dname);

        switch ($option) {
            case self::ADD_RECORD_ONLY:
                $this->put_cartActs($tree, 'FAM', $xref);
                $this->addFamilyToCart($family);
                break;

            case self::ADD_CHILDREN:
                $this->put_cartActs($tree, 'FAM_AND_CHILDREN', $xref);
                $this->addFamilyAndChildrenToCart($family);
                break;

            case self::ADD_DESCENDANTS:
                $this->put_cartActs($tree, 'FAM_AND_DESCENDANTS', $xref);
                $this->addFamilyAndDescendantsToCart($family);
                break;

            case self::ADD_PARTNER_CHAINS:
                $this->put_cartActs($tree, 'FAM_PARTNER_CHAINS', $xref);
                $this->addPartnerChainsToCartFamily($family);
                break;
        }

        return redirect($family->url());
    }

    /**
     * tbd: show options only if they will add new elements to the clippings cart otherwise grey them out
     * tbd: indicate the number of records which will be added by a button
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getAddIndividualAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        $xref = Validator::queryParams($request)->isXref()->string('xref');
        $individual = Registry::individualFactory()->make($xref, $tree);
        $individual = Auth::checkIndividualAccess($individual);
        $name       = $individual->fullName();

        $generationsParms = $request->getQueryParams()['generations'] ?? null;
        // generationsParms must be fetched by explicit extraction because it is optional ...   // EW.H
        // -> (there has been an cce-action with predefined generationParms - it has been abandoned)
        //    (but it might be useful in the future, therefore this option will be kept)
        // Validator makes the queried value mandatory.                                         // EW.H 
        // $generationsParms = Validator::queryParams($request)->integer('generations', null);
        if ($generationsParms) {
            $generations = $generationsParms;
        } else {
            $generations['A'] = $this->countAncestorGenerations($individual);
            $generations['D'] = $this->countDescendantGenerations($individual);
            $generations['Amax'] = $generations['A'];
            $generations['Amin'] = 0;
            $generations['Dmax'] = $generations['D'];
            $generations['Dmin'] = 0;
        }

        if ($individual->sex() === 'F') {
            $txt_ANC = $this->substText('%1$s and her ancestors (up to %2$s generations)', $name, '~~', '~~', 'cce_genA', $generations['A']);
            $txt_ANCF = $this->substText('%1$s, her ancestors and their families (up to %2$s generations)', $name, '~~', '~~', 'cce_genA', $generations['A']);
            $txt_DES = $this->substText('%1$s, her spouses and descendants (up to %2$s generations)', $name, '~~', '~~', 'cce_genD', $generations['D']);
            $options = [
                self::ADD_RECORD_ONLY       => $name,
                self::ADD_PARENT_FAMILIES   => I18N::translate('%s, her parents and siblings', $name),
                self::ADD_SPOUSE_FAMILIES   => I18N::translate('%s, her spouses and children', $name),
                self::ADD_ANCESTORS         => $txt_ANC,
                // self::ADD_ANCESTORS_HT      => I18N::translate('%s and her ancestors, up to 4 generations, for H-Tree', $name),
                self::ADD_ANCESTOR_FAMILIES => $txt_ANCF,
                self::ADD_DESCENDANTS       => $txt_DES,
                self::ADD_LINKED_INDIVIDUALS => I18N::translate('%s and all linked individuals', $name),
            ];
        } else {
            $txt_ANC = $this->substText('%1$s and his ancestors (up to %2$s generations)', $name, '~~', '~~', 'cce_genA', $generations['A']);
            $txt_ANCF = $this->substText('%1$s, his ancestors and their families (up to %2$s generations)', $name, '~~', '~~', 'cce_genA', $generations['A']);
            $txt_DES = $this->substText('%1$s, his spouses and descendants (up to %2$s generations)', $name, '~~', '~~', 'cce_genD', $generations['D']);
            $options = [
                self::ADD_RECORD_ONLY       => $name,
                self::ADD_PARENT_FAMILIES   => I18N::translate('%s, his parents and siblings', $name),
                self::ADD_SPOUSE_FAMILIES   => I18N::translate('%s, his spouses and children', $name),
                self::ADD_ANCESTORS         => $txt_ANC,
                // self::ADD_ANCESTORS_HT      => I18N::translate('%s and his ancestors, up to 4 generations, for H-Tree', $name),
                self::ADD_ANCESTOR_FAMILIES => $txt_ANCF,
                self::ADD_DESCENDANTS       => $txt_DES,
                self::ADD_LINKED_INDIVIDUALS => I18N::translate('%s and all linked individuals', $name),
            ];
        }
        $sp_families = $individual->spouseFamilies();
        if ( count($sp_families) > 0) {
            $options[self::ADD_PARTNER_CHAINS] = I18N::translate('the partner chains %s belongs to', $name);
        }

        $title = I18N::translate('Add %s to the clippings cart', $name);

        return $this->viewResponse($this->name() . '::' . 'add-options', [
            'options'     => $options,
            'record'      => $individual,
            'generations' => $generations,
            'title'       => $title,
            'tree'        => $tree,
            'stylesheet'    => $this->assetUrl('css/cce.css'),
            'javascript'    => $this->assetUrl('js/cce.js'),
    ]);
    }
    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function postAddIndividualAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        assert($tree instanceof Tree);

        $xref        = Validator::parsedBody($request)->string('xref');
        $option      = Validator::parsedBody($request)->string('option');
        $generationsA = Validator::parsedBody($request)->string('generationsA', 'none');
        $generationsD = Validator::parsedBody($request)->string('generationsD', 'none');
        if ($generationsA !== 'none') {
            $this->levelAncestor = (int)$generationsA;
        }
        if ($generationsD !== 'none') {
            $this->levelDescendant = (int)$generationsD;
        }

        $individual = Registry::individualFactory()->make($xref, $tree);
        $individual = Auth::checkIndividualAccess($individual);

        $_dname = 'wtVIZ-DATA~INDI_' . $xref;
        $this->putVIZdname($_dname);

        switch ($option) {
            case self::ADD_RECORD_ONLY:
                $this->put_cartActs($tree, 'INDI', $xref);
                $this->addIndividualToCart($individual);
                break;

            case self::ADD_PARENT_FAMILIES:
                $this->put_cartActs($tree, 'INDI_PARENT_FAM', $xref);
                foreach ($individual->childFamilies() as $family) {
                    $this->addFamilyAndChildrenToCart($family);
                }
                break;

            case self::ADD_SPOUSE_FAMILIES:
                $this->put_cartActs($tree, 'INDI_SPOUSE_FAM', $xref);
                foreach ($individual->spouseFamilies() as $family) {
                    $this->addFamilyAndChildrenToCart($family);
                }
                break;

            case self::ADD_ANCESTORS:
                $caDo = $xref . '|' . $this->levelAncestor;
                $this->put_cartActs($tree, 'INDI_ANCESTORS', $caDo);
                $this->addAncestorsToCart($individual, $this->levelAncestor);
                break;

            case self::ADD_ANCESTOR_FAMILIES:
                $caDo = $xref . '|' . $this->levelAncestor;
                $this->put_cartActs($tree, 'INDI_ANCESTOR_FAMILIES', $caDo);
                $this->addAncestorFamiliesToCart($individual, $this->levelAncestor);
                break;

            case self::ADD_DESCENDANTS:
                $caDo = $xref . '|' . $this->levelDescendant;
                $this->put_cartActs($tree, 'INDI_DESCENDANTS', $caDo);
                foreach ($individual->spouseFamilies() as $family) {
                    $this->addFamilyAndDescendantsToCart($family, $this->levelDescendant);
                }
                break;

            case self::ADD_PARTNER_CHAINS:
                $this->put_cartActs($tree, 'INDI_PARTNER_CHAINS', $xref);
                $this->addPartnerChainsToCartIndividual($individual, $individual->spouseFamilies()[0]);
                break;

            case self::ADD_LINKED_INDIVIDUALS:
                $this->put_cartActs($tree, 'INDI_LINKED_INDIVIDUALS', $xref);
                $_dname = 'wtVIZ-DATA~all linked_' . $xref;
                $this->putVIZdname($_dname);
                $this->addAllLinked($tree, $xref);
                break;
        
    
        }

        return redirect($individual->url());
    }

#endregion

    /**
     * POST actions
     */

#region     POST

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function postAddLocationAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        $xref = Validator::queryParams($request)->isXref()->string('xref','');

        $location = Registry::locationFactory()->make($xref, $tree);
        $location = Auth::checkLocationAccess($location);

        $this->put_cartActs($tree, 'LOC', $xref);
        $_dname = 'wtVIZ-DATA~LOC_' . $xref;
        $this->putVIZdname($_dname);

        $this->addLocationToCart($location);

        return redirect($location->url());
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function postAddMediaAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        $xref = Validator::queryParams($request)->isXref()->string('xref','');

        $media = Registry::mediaFactory()->make($xref, $tree);
        $media = Auth::checkMediaAccess($media);

        $this->put_cartActs($tree, 'MEDIA', $xref);
        $_dname = 'wtVIZ-DATA~MEDIA_' . $xref;
        $this->putVIZdname($_dname);

        $this->addMediaToCart($media);

        return redirect($media->url());
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function postAddNoteAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        $xref = Validator::queryParams($request)->isXref()->string('xref','');

        $note = Registry::noteFactory()->make($xref, $tree);
        $note = Auth::checkNoteAccess($note);

        $this->put_cartActs($tree, 'NOTE', $xref);
        $_dname = 'wtVIZ-DATA~NOTE_' . $xref;
        $this->putVIZdname($_dname);

        $this->addNoteToCart($note);

        return redirect($note->url());
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function postAddRepositoryAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        $xref = Validator::queryParams($request)->isXref()->string('xref','');

        $repository = Registry::repositoryFactory()->make($xref, $tree);
        $repository = Auth::checkRepositoryAccess($repository);

        $this->put_cartActs($tree, 'REPO', $xref);
        $_dname = 'wtVIZ-DATA~REPO_' . $xref;
        $this->putVIZdname($_dname);

        $this->addRepositoryToCart($repository);

        foreach ($this->linked_record_service->linkedSources($repository) as $source) {
            $this->addSourceToCart($source);
        }

        return redirect($repository->url());
    }

        /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getAddSourceAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $xref   = Validator::queryParams($request)->isXref()->string('xref');
        $source = Registry::sourceFactory()->make($xref, $tree);
        $source = Auth::checkSourceAccess($source);
        $name   = $source->fullName();

        $options = [
            self::ADD_RECORD_ONLY        => $name,
            self::ADD_LINKED_INDIVIDUALS => I18N::translate('%s and the individuals that reference it.', $name),
        ];

        $title = I18N::translate('Add %s to the clippings cart', $name);

        return $this->viewResponse('modules/clippings/add-options', [
            'options' => $options,
            'record'  => $source,
            'title'   => $title,
            'tree'    => $tree,
        ]);
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function postAddSourceAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        $xref   = Validator::parsedBody($request)->string('xref');
        $option = Validator::parsedBody($request)->string('option');

        $source = Registry::sourceFactory()->make($xref, $tree);
        $source = Auth::checkSourceAccess($source);

        $caDo = $xref;
        if ($option === self::ADD_LINKED_INDIVIDUALS) {
            $caDo = $caDo . '|ali';
        }
        $this->put_cartActs($tree, 'SOUR', $caDo);

        $this->addSourceToCart($source);

        $_dname = 'wtVIZ-DATA~SOUR_' . $xref;
        $this->putVIZdname($_dname);

        if ($option === self::ADD_LINKED_INDIVIDUALS) {
            foreach ($this->linked_record_service->linkedIndividuals($source) as $individual) {
                $this->addIndividualToCart($individual);
            }
            foreach ($this->linked_record_service->linkedFamilies($source) as $family) {
                $this->addFamilyToCart($family);
            }
        }

        return redirect($source->url());
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function postAddSubmitterAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        $xref = Validator::queryParams($request)->isXref()->string('xref','');

        $submitter = Registry::submitterFactory()->make($xref, $tree);
        $submitter = Auth::checkSubmitterAccess($submitter);

        $this->put_cartActs($tree, 'SUBM', $xref);
        $_dname = 'wtVIZ-DATA~SUBM_' . $xref;
        $this->putVIZdname($_dname);

        $this->addSubmitterToCart($submitter);

        return redirect($submitter->url());
    }

#endregion


    /**
     * enhanced add-actions
     */

#region     enhanced add-actions

    /**
     * @param Family $family
     * @return void
     */
    public function addPartnerChainsToCartFamily(Family $family): void
    {
        if ($family->husband() instanceof Individual) {
            $this->addPartnerChainsToCartIndividual($family->husband(), $family);
        } elseif ($family->wife() instanceof Individual) {
            $this->addPartnerChainsToCartIndividual($family->wife(), $family);
        }
    }

    /**
     * @param Individual $indi
     * @param Family $family
     * @return void
     */
    public function addPartnerChainsToCartIndividual(Individual $indi, Family $family): void
    {
        $partnerChains = new PartnerChains($indi, $family);
        $root = $partnerChains->getChainRootNode();

        if ($partnerChains->countPartnerChains($root->chains) > 0) {
            $this->addIndividualToCart($root->indi);
            $this->addFamilyToCart($root->fam);
            foreach ($root->chains as $chain) {
                $this->addPartnerChainsToCartRecursive($chain);
            }
        }
    }

    /**
     * @param object $node partner chain node
     * @return void
     */
    public function addPartnerChainsToCartRecursive(object $node): void
    {
        if ($node && $node->indi instanceof Individual) {
            $this->addIndividualToCart($node->indi);
            $this->addFamilyToCart($node->fam);
            foreach ($node->chains as $chain) {
                $this->addPartnerChainsToCartRecursive($chain);
            }
        }
    }

    /**
     * add all members of partner chains in a tree to the clippings cart (spouses or partners of partners)
     *
     * @param Tree $tree
     */
    public function addPartnerChainsGlobalToCart(Tree $tree): void
    {
        $partnerChains = new PartnerChainsGlobal($tree, ['HUSB', 'WIFE']);
        // ignore all standard families (chains have at least 3 partners)
        foreach ($partnerChains->getFamilyCount() as $family => $count) {
            if ($count > 2) {
                $familyObject = Registry::familyFactory()->make($family, $tree);
                if ($familyObject instanceof Family && $familyObject->canShow()) {
                    $this->addFamilyToCart($familyObject);
                }
            }
        }
    }

    /**
     * add all circles (closed loops) of individuals in a tree to the clippings cart
     * by adding individuals and their families without spouses to the clippings cart
     *
     * @param Tree $tree
     */
    public function addAllCirclesToCart(Tree $tree): void
    {
        $circles = new AncestorCircles($tree, ['FAMS', 'FAMC','ALIA']);
        foreach ($circles->getXrefs() as $xref) {
            $object = Registry::individualFactory()->make($xref, $tree);
            if ($object instanceof Individual) {
                if ($object->canShow()) {
                    $this->addIndividualToCart($object);
                }
            } else {
                $object = Registry::familyFactory()->make($xref, $tree);
                if ($object instanceof Family && $object->canShow()) {
                    $this->addFamilyWithoutSpousesToCart($object);
                }
            }
        }
    }

    /**
     * add all linked individuals in a tree to the clippings cart
     * by adding individuals and their families without spouses to the clippings cart
     *
     * @param Tree $tree
     */
    public function addAllLinked(Tree $tree, $xref = null): void
    {
        $allconns = new AllConnected($tree, ['FAMS', 'FAMC', 'ALIA', 'ASSO', '_ASSO'], $xref);
        foreach ($allconns->getXrefs() as $xref) {
            $object = Registry::individualFactory()->make($xref, $tree);
            if ($object instanceof Individual) {
                if ($object->canShow()) {
                    $this->addIndividualToCart($object);
                }
            } else {
                $object = Registry::familyFactory()->make($xref, $tree);
                if ($object instanceof Family && $object->canShow()) {
                    $this->addFamilyWithoutSpousesToCart($object);
                }
            }
        }
    }

    /**
     * add all persons and families in this family tree
     *
     * @param Tree $tree
     */
    public function addCompleteGEDtoCart(Tree $tree): void
    {
        $xrefsIF = new CompleteGED($tree);

        // we want only INDI - switch all_RecTypes temporarly

        $all_RT = $this->all_RecTypes;
        $this->all_RecTypes = false;

        // put collected xrefs to cart

        foreach ($xrefsIF->getXrefsI() as $xref) {
            $object = Registry::individualFactory()->make($xref, $tree);
            if ($object instanceof Individual) {
                if ($object->canShow()) {
                    $this->addIndividualToCart($object);
                }
            }
        }
        foreach ($xrefsIF->getXrefsF() as $xref) {
            $object = Registry::familyFactory()->make($xref, $tree);
            if ($object instanceof Family && $object->canShow()) {
                $this->addFamilyWithoutSpousesToCart($object);
            }
        }

        $this->all_RecTypes = $all_RT;
    }

    /**
     * @param Family $family
     */
    public function addFamilyWithoutSpousesToCart(Family $family): void
    {
        $tree = $family->tree()->name();
        $xref = $family->xref();

        $do_cart = $this->put_Cart($tree, $xref);
    }

    /**
     * @param Family $family
     */
    public function addFamilyOtherRecordsToCart(Family $family): void
    {
        $this->addLocationLinksToCart($family);
        $this->addNoteLinksToCart($family);
        $this->addSourceLinksToCart($family);
        $this->addSubmitterLinksToCart($family);
    }

#endregion


    /**
     * redefined add-actions
     */

#region     enhanced add-actions

    /**
     * @param Family $family
     */
    public function addFamilyToCart(Family $family): void
    {
        // if ($addAct) 
            // $this-put_cartActs($family->tree(),"ADD_FAM~",  $family->xref());

        foreach ($family->spouses() as $spouse) {
            $this->addIndividualToCart($spouse);
        }
        $this->addFamilyWithoutSpousesToCart($family);

        $this->addMediaLinksToCart($family);

        if ( $this->all_RecTypes) {                                // EW.H mod ...
            $this->addFamilyOtherRecordsToCart($family);
        }
    }

    /**
     * @param Individual $individual
     */
    public function addIndividualToCart(Individual $individual): void
    {
        $tree = $individual->tree()->name();
        $xref = $individual->xref();

        $do_cart = $this->put_Cart($tree, $xref);
        if ($do_cart) {
            $this->addMediaLinksToCart($individual);

            if ( $this->all_RecTypes) {                                // EW.H mod ...
                $this->addLocationLinksToCart($individual);
                $this->addNoteLinksToCart($individual);
                $this->addSourceLinksToCart($individual);
            }
        }
    }

#endregion

}
