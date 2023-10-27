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

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Tree;

/**
 * Trait CCEcartActions - bundling all actions regarding Session::cart
 */
trait CCEcartActions
{
    public string $cartAction;

    public array $cartXREFs;

    private function getCart() : array
    {
        // clippings cart is an array in the session specific for each tree
        $cart  = Session::get('cart', []);
        if ( !is_array($cart) ) {
            $cart = [];
            Session::put('cart', $cart);
        }
        return $cart;
   }

   private function removeCart(Tree $tree, $xref) : void
   {
        $cart = Session::get('cart', []);
        unset($cart[$tree->name()][$xref]);
        Session::put('cart', $cart);
   }

   private function cleanCart(Tree $tree) : array
   {
       $cart = Session::get('cart', []);
       $cart[$tree->name()] = [];
       Session::put('cart', $cart);
       return $cart;
   }

   private function getCartActs(Tree $tree) : array
   {
       $cartAct = Session::get('cartActs', []);
       $cartacts = array_keys($cartAct[$tree->name()] ?? []);
       $cartacts = array_map('strval', $cartacts);           // PHP converts numeric keys to integers.
       return $cartacts;
   }

   private function cleanCartActs(Tree $tree) : array
    {
        $cartAct = Session::get('cartActs', []);
        $cartAct[$tree->name()] = [];
        Session::put('cartActs', $cartAct);

        $cartActsDiversity = Session::get('cartActsDiversity', []);
        $cartActsDiversity[$tree->name()] = [];
        $cartActsDiversity[$tree->name()][0] = 0;
        Session::put('cartActsDiversity', $cartActsDiversity);

        return $cartAct;
    }

    private function cleanCartActs_cact(Tree $tree, string $cact) : array
    {
        $cartAct = Session::get('cartActs', []);
        unset($cartAct[$tree->name()][$cact]);
        Session::put('cartActs', $cartAct);
        return $cartAct;
    }

    private function put_cartActs(Tree $tree, string $action, string $Key, string $altKey = '') : string
    {
        $cartAct = Session::get('cartActs', []);
        $retval = $action;
        if ($altKey == '') {
            $caction = $action . '~' . $Key;
            $this->cartAction = $caction;
        } else {
            [$retval, $caction] = $this->put_cartActs_alt($tree, $action, $Key, $altKey);
        }

        if (($cartAct[$tree->name()][$caction] ?? false) === false) {
            $cartAct[$tree->name()][$caction] = true;
            Session::put('cartActs', $cartAct);
        }
        return $retval;
    }
    private function put_cartActs_alt(Tree $tree, string $action, string $Key, string $altKey): array
    {
        $caction = $action . '~' . $altKey;                                         // this combination may not be significant ...

        $cartActsDiversity = Session::get('cartActsDiversity', []);                 // ... so we generate a identifying prefix ...
        if (($cartActsDiversity[$tree->name()][0] ?? false) === false)
            $cartActsDiversity[$tree->name()] [0] = 0;                              // ... quite simply - a serial number ...
        $cALc = $cartActsDiversity[$tree->name()] [0];
        $cALc += 1;                                                                 // ... each entry gets a consecutive number ...
        $caction = '('.(string) $cALc .')'. $caction;
        $cartActsDiversity[$tree->name()][0] = $cALc;                               // ... that is stored ...
        $cartActsDiversity[$tree->name()][$caction] = true;                         // ... and the entry is filed
        Session::put('cartActsDiversity', $cartActsDiversity);

        // $cartActs = Session::get('cartActs', []);
        // if (($cartActs[$tree->name()][$caction] ?? false) === false) {
        //     $cartActs[$tree->name()][$caction] = true;
        //     Session::put('cartActs', $cartActs);
        // }
        $this->cartAction = $caction;
        $retval = $this->cartAction;
        $caction = $caction . '|' . $Key;
        return [$retval, $caction];
    }

}