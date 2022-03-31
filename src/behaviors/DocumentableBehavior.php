<?php
namespace pixium\documentable\behaviors;

use Exception;
use pixium\documentable\DocumentableException;
use \yii\db\ActiveRecord;
use \yii\base\Behavior;
use \pixium\documentable\models\Document;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\VarDumper;
use yii\web\UploadedFile;

/**
 * DocumentableBehaviour allows attaching one or multiple documents to a model attribute
 * the model the document is attached to is specified by:
 * - table_name
 * - model_id and,
 * - rel_type_tag (this to be able to group documents for one model type think AVATAR_IMG, RESUME, BEST_PICS)
 * or (lionel.aimerie@pixiumdigital (2021-08-03))
 * - by a rel_classname defining a via table (e.g. `user_document`)
 *
 * add to Model
 *   [
 *      'class' => pixium\documentable\behaviors\DocumentableBehavior::className(),
 *      'filter' => [
 *          'attribute1' => [ // the property name is the default tag
 *            'tag' => 'AVATAR',            // relation_type_tag in document (if not specified `attribute1` will be used)
 *            'rel_classname' => User::class, // class to use as a via rel
 *            'multiple' =>  false,         // true, false accept multiple uploads
 *            'replace' => false,           // force replace of existing images
 *            'thumbnail' => false,         // create thumbnails for images
 *                  thumbnail size is defined in params [
 *                      'thumbnail_size' => [
 *                          'width' => 200, 'height' => 200, // or...
 *                          'square' => 200,
 *                          'crop' => true  // crop will fit the smaller edge in the defined box
 *                       ],
 *                      'quality' => 70, 0-100 smaller generates smaller (and uglier) files used by jpg/webp
 *                      'compression' => 7, 0-10 bigger generates smaller files. used by png
 *                      'thumbnail_background_color' => 'FFF',
 *                      'thumbnail_background_alpha' => 0,
 *                      'thumbnail_type' => 'png',
 *                      // TODO: change thumbnail_size to documentable_thumbnail [ all in ]
 *                      // TODO: add thmubnail definition per documentable
 *                      // make all documentable globl params as well
 *                  ]
 *            'unzip' => true,              // bool or 'unzip' => ['image/png', types to unzip...]
 *
 *            // For Widget only
 *            'mimetypes' => 'image/jpeg,image/png' // csv string of mimetypes
 *            'maxsize' => 500,             // max file size
 *            'extensions' => ['png','jpg'] // array of file extensions without the dot
 *            // advanced
 *            __TODO__ 'on' => ['create', 'update'], // scenarii for attaching
 *          ],
 *      ]
 *   ]
 */
class DocumentableBehavior extends Behavior
{
    /**
     * @property array $filter array of atribute names to process
     */
    public $filter = [];

    /**
     * {@inheritdoc}
     */
    public function events()
    {
        return [
            // ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            // ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            // ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            // ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
            // After deletion of owner model, delete all DocumentRel attach to it and all orphan Documents
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',

            // After creation or update, run afterSave to attach files given to the owner model
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
        ];
    }


    /**
     * after the model has been saved, attach documents based
     * on properties passed (not attributes)
     * @param $event
     */
    public function afterSave($event)
    {
        foreach ($this->filter as $prop => $options) {
            if (!$this->owner->hasProperty($prop)) {
                continue;
            }
            
            /** @var ActiveRecord $model */
            $model = $this->owner;
            // avoid double loading files
            // if ($model->{$prop} === true) {
            //     continue;
            // } 
            // $model->documentable_inserts[$prop] = true;

            // avoid multiple attempts to save the same file
            // if (!($model->{$prop} instanceof UploadedFile)) {
            //     continue;
            // }

            // for each prop get file(s), upload it(them)
            // do it here not in the controllers.... simplifies the flow
            $files = \yii\web\UploadedFile::getInstances($model, $prop);
            // skip (in case of multiple filters attached to the behaviour)
            if (empty($files)) {
                continue;
            }

            $model->{$prop} = $files;

            // process this property
            $multiple = $options['multiple'] ?? false;
            // if (!$multiple && !empty($files)) {
            //     // for unique attachments, clear Documents of given tag first
            //     // clear only if new documents are given (think of the update scenario)
            //     $this->deleteDocs($prop);
            //     // Document::deleteForModel($model, $options);
            // }

            if (!is_array($files)) {
                throw new \yii\base\UserException('DocumentableBehavior afterSave expects an array of files');
            }
            $exisitingDocIds = $this->getDocs($prop)->select('id')->asArray()->column();
            $this->owner->{$prop} = $this->getDocs($prop)->all();
            try {
                foreach ($files as $file) {
                    Document::uploadFileForModel(
                        $file,
                        $model,
                        $options['tag'] ?? $prop,
                        $options
                    );
                    if (!$multiple) {
                        // handles the case multiple files where given but only one is required by the model
                        break;
                    }
                }
                // all went well, delete old docs
                if (!$multiple && !empty($files)) {
                    Document::deleteAll(['id' => $exisitingDocIds]);
                }
            } catch(Exception $e) {
                // DBG: throw $e;
                return false;
            }
            return true;
            // reset file property on owner [ERROR]
            // $this->owner->{$prop} = $this->getDocs($prop)->all();
            
        }
    }

    /**
     * after Delete
     * cleanup the document_rel attached to this model,
     */
    public function afterDelete()
    {
        $model = $this->owner;
        $tableName = $this->unquotedTableName();
        $docs = Document::findAll(['rel_table' => $tableName, 'rel_id' => $model->id]);
        foreach ($docs as $doc) {
            // DocumentRel->delete() cascades delete to Document.
            $doc->delete();
        }
    }

    /**
     * return unquoted {{%tablename}}
     * @return string
     */
    public function unquotedTableName() {
        $model = $this->owner;
        return preg_replace('/\`/', '', $model->getDb()->quoteSql($model->tableName()));
    }


    //=== ACCESSORS
    /**
     * get Docs
     *  if no attribute is given get all docs
     * @param string $prop property name
     * @return ActiveQuery array of Document
     */
    public function getDocs($prop = null)
    {
        $model = $this->owner;
        $options = $this->filter[$prop] ?? [];
        $relTypeTag = $options['tag'] ?? $prop ?? null;
        // find documents via 
        // 1. rel table (table) (fast) using rel_classname, or
        // 2. via rel_table, rel_id
        $relClass = $options['rel_classname'] ?? false;
        $tableName = $this->unquotedTableName();

        // TODO: ensure model has property "{$tableName}_id"
        $query = (false == $relClass) 
        ? Document::find()
            ->where(['rel_id' => $model->id])
            ->andWhere(['rel_table' => $tableName]) 
        : $model->hasMany(Document::class, ['id' => 'document_id'])
            ->viaTable($relClass::tableName(), ["{$tableName}_id" => 'id']);

        return $query->andFilterWhere(['rel_type_tag' => $relTypeTag])
            ->orderBy(['rank' => SORT_ASC]);
    }    



    /**
     * Provided as a quick way to retrieve a doc
     * @param string $prop property name
     * @param array $options html options for img tag
     * @param string $default tag generated if no image is available
     * @param bool $asThumb
     */
    private function getFirstImage($prop, $options = [], $default = null, $asThumb = false)
    {
        // return first thumbanil for the property
        if (null !== ($doc1 = $this->getDocs($prop)->one())) {
            /** @var Document doc1 */
            // get thumbnail url
            if (null !== ($url = $doc1->getURI(!$asThumb))) {
                return Html::img($url, $options);
            }
        }
        // user default
        if (null != $default) {
            return $default;
        }
        // component default
        /** @var DocumentableComponent $docsvc */
        $docsvc = \Yii::$app->documentable;
        return $docsvc->getThumbnailDefault($options);
    }

    /**
     * Helper: Provided as a quick way to retrieve the first image given as property
     * @param string $prop property name
     * @param array $options html options for img tag
     * @param string $default tag generated if no image is available
     */
    public function getThumbnail($prop, $options = [], $default = null)
    {
        return $this->getFirstImage($prop, $options, $default, true);
    }

    /**
     * Helper: Provided as a quick way to retrieve the first image given as property
     * @param string $prop property name
     * @param array $options html options for img tag
     * @param string $default tag generated if no image is available
     */
    public function getImage($prop, $options = [], $default = null)
    {
        return $this->getFirstImage($prop, $options, $default, false);
    }

    /**
     * copy docs associated with one attribute TO a given model
     * @param string $prop
     * @param ActiveRecord $model target model to copy to
     * @throws DocumentableException
     */
    public function copyDocs($prop, $model)
    {
        if (!$model->hasMethod('getDocs')) {
            throw new DocumentableException(DocumentableException::DEXC_NOT_DOCUMENTABLE, 'Target object is not a Documentable');
        }

        $options = $this->filter[$prop] ?? [];
        $relClass = $options['rel_classname'] ?? false;

        $docs = $this->getDocs($prop)->all();
        foreach ($docs as $doc) {
            /** @var Document $doc */
            $doc->copyToModel($model, $prop, $relClass);
        }
    }

    /**
     * mass delete of multiple docs based on attribute name
     * @param string $prop
     */
    public function deleteDocs($prop)
    {
        $docs = $this->getDocs($prop)->all();
        foreach ($docs as $doc) {
            /** @var Document $doc */
            $doc->delete(false); // skip group updates as we delete all
        }
    }

    /**
     * uploadFile
     * @param string $prop
     * @param UploadedFile|string File or path to file
     * @param array $options DocumentableBehaviour attribute options
     */
    public function uploadFile($prop, $fileOrPath, $options = [])
    {
        $model = $this->owner;
        $options = ArrayHelper::merge($this->filter[$prop], $options);
        // DBG:
        // VarDumper::dump([
        //     'dbg' => 'uploadFile',
        //     'prop' => $prop,
        //     'options' => $options,
        // ]);
        Document::uploadFileForModel(
            $fileOrPath,
            $model,
            $options['tag'] ?? $prop,
            $options
        );
    }
}
