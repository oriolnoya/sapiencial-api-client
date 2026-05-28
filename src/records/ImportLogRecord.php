<?php

namespace sapiencial\sapiencialapiclient\records;

use craft\db\ActiveRecord;

class ImportLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%sapiencial_import_logs}}';
    }
}
