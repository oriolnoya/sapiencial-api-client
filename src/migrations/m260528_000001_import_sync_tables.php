<?php

namespace sapiencial\sapiencialapiclient\migrations;

use craft\db\Migration;

class m260528_000001_import_sync_tables extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%sapiencial_entity_map}}')) {
            $this->createTable('{{%sapiencial_entity_map}}', [
                'id' => $this->primaryKey(),
                'remoteType' => $this->string(30)->notNull(),
                'remoteId' => $this->integer()->notNull(),
                'sourceSite' => $this->string(64)->notNull(),
                'entryId' => $this->integer()->notNull(),
                'parentEntryId' => $this->integer()->null(),
                'titleSnapshot' => $this->string()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%sapiencial_entity_map}}', ['remoteType', 'remoteId', 'sourceSite'], true);
            $this->addForeignKey(null, '{{%sapiencial_entity_map}}', ['entryId'], '{{%entries}}', ['id'], 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%sapiencial_entity_map}}', ['parentEntryId'], '{{%entries}}', ['id'], 'SET NULL', 'CASCADE');
        }

        if (!$this->db->tableExists('{{%sapiencial_import_logs}}')) {
            $this->createTable('{{%sapiencial_import_logs}}', [
                'id' => $this->primaryKey(),
                'mode' => $this->string(20)->notNull(),
                'remoteBookId' => $this->integer()->notNull(),
                'sourceSite' => $this->string(64)->notNull(),
                'status' => $this->string(20)->notNull(),
                'countsJson' => $this->text()->null(),
                'errorsJson' => $this->text()->null(),
                'durationMs' => $this->integer()->notNull()->defaultValue(0),
                'dryRun' => $this->boolean()->notNull()->defaultValue(false),
                'createdAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%sapiencial_import_logs}}', ['remoteBookId', 'sourceSite', 'status'], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%sapiencial_import_logs}}');
        $this->dropTableIfExists('{{%sapiencial_entity_map}}');
        return true;
    }
}
