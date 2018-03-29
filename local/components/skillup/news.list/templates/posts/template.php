<?php
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

var_dump($arResult);?>
<div class="col-5">
    <?foreach ($arResult as $aItem):?>
    <div>Название: <?= $aItem['NAME'];?></div>
    <div>Текст: <?= $aItem['DETAIL_TEXT_TYPE']?></div>
    <div>Лукасы: <?= $aItem['PROPERTY_LIKE_VALUE']?></div>
    <?endforeach;?>
</div>
