<?php

namespace sapiencial\sapiencialapiclient\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class FieldAssetBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@sapiencial/sapiencialapiclient/assets/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['field.v2.js'];
        parent::init();
    }
}
