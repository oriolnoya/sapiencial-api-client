<?php

namespace sapiencial\sapiencialapiclient\migrations;

use craft\db\Migration;

class m260527_000001_create_sapiencial_api_fetch_cache extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%sapiencial_api_fetch_cache}}')) {
            $this->createTable('{{%sapiencial_api_fetch_cache}}', [
                'id' => $this->primaryKey(),
                'entryId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'fieldHandle' => $this->string(64)->notNull(),
                'remoteType' => $this->string(20)->notNull(),
                'remoteId' => $this->integer()->notNull(),
                'payloadJson' => $this->longText()->notNull(),
                'payloadHash' => $this->string(64)->notNull(),
                'fetchedAt' => $this->dateTime()->notNull(),
                'lastCheckedAt' => $this->dateTime()->notNull(),
                'status' => $this->string(16)->notNull()->defaultValue('ok'),
                'error' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(
                null,
                '{{%sapiencial_api_fetch_cache}}',
                ['entryId', 'siteId', 'fieldHandle', 'remoteType', 'remoteId'],
                true
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%sapiencial_api_fetch_cache}}');
        return true;
    }
}
