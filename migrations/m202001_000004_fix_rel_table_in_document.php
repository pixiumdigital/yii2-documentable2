<?php

use yii\db\Migration;

/**
 * Updates rel_table using non pre-quoted types table `{{%document}}`.
 */
class m202001_000004_fix_rel_table_in_document extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $connection = $this->getDb();
        if ('mysql' == $connection->getDriverName()) {
            $connection->createCommand('
                UPDATE `document` SET rel_table = REGEXP_REPLACE(rel_table, "[\{\}\%]", "");
            ')->execute();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        
    }
}
