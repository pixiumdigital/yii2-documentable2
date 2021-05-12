<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%document_rel}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%document}}`
 * - `{{%document_rel_type}}`
 */
class m202001_000003_move_doc_rel_to_document_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%document}}', 'rel_id', $this->integer()->notNull()->after('id'));
        $this->addColumn('{{%document}}', 'rel_table', $this->string(64)->notNull()->after('id'));
        $this->addColumn('{{%document}}', 'rel_type_tag', $this->string(64)->after('rel_id'));
        $this->addColumn('{{%document}}', 'rank', $this->integer()->notNull()->defaultValue(0)->after('rel_type_tag'));
        // raw update without using the DocumentRel class

        $connection = $this->getDb();

        // clean erroneous singletons
        $connection->createCommand('
            delete from document
            where `id` not in (select document_id from document_rel); 
        ')->execute();

        // relink
        $connection->createCommand('
            update `document` d
            left join document_rel dr on d.id=dr.document_id
            set d.rel_table=dr.rel_type, d.rel_id=dr.rel_id, d.`rank`=dr.`rank`, d.rel_type_tag=dr.rel_type_tag
        ')->execute();

        // get rid of the old stuff
        // drops foreign key for table `{{%document}}`
        $this->dropForeignKey(
            '{{%fk-document_rel-document_id}}',
            '{{%document_rel}}'
        );

        // drops index for column `document_id`
        $this->dropIndex(
            '{{%idx-document_rel-document_id}}',
            '{{%document_rel}}'
        );

        $this->dropTable('{{%document_rel}}');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        // this migration cannot be reverted
        return false;
    }
}
