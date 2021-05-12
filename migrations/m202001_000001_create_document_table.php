<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%document}}`.
 */
class m202001_000001_create_document_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            // use unicode
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable('{{%document}}', [
            'id' => $this->primaryKey(),
            'rel_id' => $this->integer()->notNull(),
            'rel_table' => $this->string(64)->notNull(),
            'rel_type_tag' => $this->string(64),
            'rank' => $this->integer()->notNull()->defaultValue(0),
            'title' => $this->string(255)->notNull(),
            'url_thumb' => $this->string(250),
            'url_master' => $this->string(255)->notNull(),
            'size' => $this->integer()->defaultValue(0),
            'created_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_at' => $this->integer(),
            'updated_by' => $this->integer(),
        ], $tableOptions);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%document}}');
    }
}
