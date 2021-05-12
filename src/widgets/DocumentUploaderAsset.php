<?php
namespace pixium\documentable\widgets;

use yii\web\AssetBundle;

class DocumentUploaderAsset extends AssetBundle
{
    public $sourcePath = '@app/widgets/docuploader/assets';
    // public $basePath = '@webroot';
    // public $baseUrl = '@web';
    //public $css = ['assets/main.css'];
    public $js = [
        'js/main.js'
    ];
    public $depends = [
        'yii\web\JqueryAsset'
    ];

    public function init()
    {
        // Tell AssetBundle where the assets files are
        $this->sourcePath = __DIR__.'/assets';
        parent::init();
    }
}
