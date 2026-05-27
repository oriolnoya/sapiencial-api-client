<?php

namespace sapiencial\sapiencialapiclient\records;

use craft\db\ActiveRecord;

class FetchCacheRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%sapiencial_api_fetch_cache}}';
    }
}
