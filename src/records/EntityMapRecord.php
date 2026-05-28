<?php

namespace sapiencial\sapiencialapiclient\records;

use craft\db\ActiveRecord;

class EntityMapRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%sapiencial_entity_map}}';
    }
}
