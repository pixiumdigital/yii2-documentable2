<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%document}}`.
 */
class m202001_000002_add_copy_group_id_column_to_document_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%document}}', 'copy_group', $this->integer()->after('rank'));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%document}}', 'copy_group');
    }
}
