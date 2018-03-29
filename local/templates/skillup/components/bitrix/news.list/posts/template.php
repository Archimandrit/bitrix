<?if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();?>

<div class="contsiner">
    <div class="row">
        <? foreach ($arResult['ITEMS'] as  $aItem): ?>

        <div class="col-4">
            <a href="<?= $aItem['DETAIL_PAGE_URL']?>">
            <img src="<?= $aItem['DETAIL_PICTURE']['src'];?>"></a>
            <?
            ?>
            <div>
                <?= $aItem['AUTHOR']?>
            </div>
        </div>
        <?endforeach;?>
    </div>

</div>



