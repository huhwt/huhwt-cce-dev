<?php

/*
 * webtrees - clippings cart enhanced
 *
 * Copyright (C) 2023 huhwt. All rights reserved.
 *
 * webtrees: online genealogy / web based family history software
 * Copyright (C) 2021 webtrees development team.
 *
 * This module handles the Ajax-Requests of injected CCE-function in ListModules.
 * It takes the reported XREF's and performs the collecting operations.
 * 
 */

declare(strict_types=1);

namespace HuHwt\WebtreesMods\ClippingsCartEnhanced;

use Fisharebest\Webtrees\Module\IndividualListModule;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Tree;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use HuHwt\WebtreesMods\ClippingsCartEnhanced\Traits\CC_addActions;
use HuHwt\WebtreesMods\ClippingsCartEnhanced\Traits\CCEaddActions;
use HuHwt\WebtreesMods\ClippingsCartEnhanced\Traits\CCEcartActions;
use HuHwt\WebtreesMods\ClippingsCartEnhanced\Traits\CCEvizActions;


/**
 * Class ClippingsCartEnhancedModule
 * 
 * @author  EW.H <GIT@HuHnetz.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/huhwt/huhwt-cce/
 */

 class ClippingsCartEnhancedModule extends AbstractModule implements RequestHandlerInterface
 {
    /** All constants and functions related to default ClippingsCartModule  */
    use CC_addActions;
    /** All constants and functions related to enhancements  */
    use CCEaddActions {
        CCEaddActions::addFamilyToCart insteadof CC_addActions;
        CCEaddActions::addIndividualToCart insteadof CC_addActions;
    }
    /** All constants and functions related to handling the Cart  */
    use CCEcartActions;
    /** All constants and functions related to connecting vizualizations  */
    use CCEvizActions;

     private $huh;

    /**
     * Retrieve all Record-Types
     * @var boolean
     */
    private bool $all_RecTypes;

     /**
     * @var array $cart
     */
    private array $cart;

     public function __construct() {
         $this->huh = json_decode('"\u210D"');

         $this->cart = $this->getCart();

         $this->all_RecTypes        = true;
        }

     /**
      * EW.H MOD ... Switch over to 'clip_families'
      *
      * @param ServerRequestInterface $request
      *
      * @return ResponseInterface
      *
      */
     public function handle(ServerRequestInterface $request): ResponseInterface
     {
        $action = Validator::queryParams($request)->string('action');

        if ( $action == 'clipFamilies' ) {
            return response($this->clip_families($request));
        }

        if ( $action == 'CartActRemove' ) {
            return response($this->doCartActRemove($request));
        }
        return response('_NIX_');
    }

    /**
     * Fetch a list of families with specified names
     * To search for unknown names, use $surn="@N.N.", $salpha="@" or $galpha="@"
     * To search for names with no surnames, use $salpha=","
     *
     * @param ServerRequestInterface $request
     * 
     * @return string           number of records in cart
     */
    public function clip_families(ServerRequestInterface $request): string
    {
        $tree = Validator::attributes($request)->tree();

        // with parents?
        $boolWp = (Validator::queryParams($request)->string('boolWp', 'no') == 'yes');

        // the actual search parameters of origin request
        $actSEARCH = $this->cleanSearch(Validator::queryParams($request)->string('actSEARCH', ''));
        $actSEARCH_p = $this->getSearch($actSEARCH);

        // the actual page in DataTable
        $actPage_ = (Validator::queryParams($request)->string('actPage',''));
        $actPage = $actSEARCH_p['surname'] . '=' . $actPage_ . '=';

        // the XREFs
        $xrefs = Validator::queryParams($request)->string('xrefs', '');
        if ($xrefs == '')
            return (string) $this->count_Records($tree);

        $XREFs = explode(';', $xrefs);

        $families = $this->make_GedcomRecords($tree, $XREFs);

        $caKey = $boolWp ? 'FAM-LISTwp' : 'FAM-LIST';
        $caKey = $this->put_cartActs($tree, $caKey, $actSEARCH, $actPage);
        $_dname = 'wtVIZ-DATA~' . $caKey . '|' . $actSEARCH;
        $this->putVIZdname($_dname);

        foreach($families  as $family) {
            $this->addFamilyToCart($family);
        }
        if ($boolWp) {
            foreach($families  as $family) {
                $indi_wife = $family->wife();
                if ($indi_wife)
                    $this->toCartParents($indi_wife);
                $indi_husb = $family->husband();
                if ($indi_husb)
                    $this->toCartParents($indi_husb);

            }
        }

        return (string) $this->count_Records($tree);
    }

    protected function toCartParents(Individual $individual) {
        foreach ($individual->childFamilies() as $family) {
            $this->addFamilyToCart($family);
        }
    }

    /**
     * 
     *
     * @param Tree            $tree
     * @param array<string>   $XREFs
     * 
     * @return array
     */
    public function make_GedcomRecords(Tree $tree, array $XREFs): array
    {
        $records = array_map(static function (string $xref) use ($tree): ?GedcomRecord {
            return Registry::gedcomRecordFactory()->make($xref, $tree);
        }, $XREFs);


        return $records;
    }

    /**
     * @param Tree            $tree
     */
    private function count_Records(Tree $tree) : int
    {
        $cart = Session::get('cart', []);
        $xrefs = ($cart[$tree->name()]);
        return count($xrefs);
    }

    private function cleanSearch($p_actSearch) : string
    {
        if ($p_actSearch == '')
            return '';

        $actSearch_x = explode('&', $p_actSearch);

        $actSearch_ = [];
        foreach($actSearch_x  as $search) {
            if ($search > '') {
                $search_x = explode('=', $search);
                if ($search_x[1] > '') {
                    $search_ = $search_x[0] . '=' . $search_x[1];
                    $actSearch_ [] = $search_;
                }

            }
        }

        $actSearch = '&' . implode('&', $actSearch_);

        return $actSearch;
    }

    private function getSearch($p_actSearch) : array
    {
        if ($p_actSearch == '')
            return [''];

        $actSearch_x = explode('&', $p_actSearch);

        $actSearch_ = [];
        foreach($actSearch_x  as $search) {
            if ($search > '') {
                $search_x = explode('=', $search);
                if ($search_x[1] > '') {
                    $actSearch_ [$search_x[0]] = $search_x[1];
                }

            }
        }

        return $actSearch_;
    }

    private function doCartActRemove(ServerRequestInterface $request): string
    {
        $tree = Validator::attributes($request)->tree();

        // the actual page in DataTable
        $cartAct = (Validator::queryParams($request)->string('cartact',''));
        $cAct = $cartAct.str_contains($cartAct,'|') ? substr($cartAct,0,stripos($cartAct,'|')) : $cartAct;
        // the XREFs
        $xrefs = Validator::queryParams($request)->string('xrefs', '');
        if ($xrefs == '' || $cartAct == '')
            return (string) $this->count_Records($tree);

        $XREFs = explode(';', $xrefs);

        $cart = Session::get('cart', []);

        $_tree = $tree->name();
        foreach ($XREFs as $xref) {
            if (($cart[$_tree][$xref] ?? '_NIX_') != '_NIX_') {
                $xref_action = $cart[$_tree][$xref];
                $xref_actions = explode(';', $xref_action);
                $ica = array_search($cAct, $xref_actions);
                array_splice($xref_actions, $ica,1);
                if (count($xref_actions) > 0) {
                    $xref_action = $xref_actions[0];
                    if (count($xref_actions) > 1)
                        $xref_action = implode(';', $xref_actions);
                    $cart[$_tree][$xref] = $xref_action;
                } else {
                        unset($cart[$_tree][$xref]);
                }
            }
        }

        Session::put('cart', $cart);

        $this->cleanCartActs_cact($tree, $cartAct);

        return (string) $this->count_Records($tree);

    }
}