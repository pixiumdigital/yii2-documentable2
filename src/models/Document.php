<?php

namespace pixium\documentable\models;

use Aws\S3\S3Client;
use Exception;
use pixium\documentable\DocumentableComponent;
use pixium\documentable\DocumentableException;
use Yii;
// imagine to create thumbs out of images
use \yii\imagine\Image;
use \yii\helpers\FileHelper;
use \yii\db\ActiveRecord;
use yii\web\UploadedFile;

// use yii]imagine\

//use Imagine\Image\ManipulatorInterface;

/**
 * This is the model class for table "document".
 *
 * @property int $id
 * @property string $title
 * @property string $url_thumb
 * @property string $url_master
 * @property int $created_at
 * @property int $created_by
 * @property int $updated_at
 * @property int $updated_by
 * @property int $copy_group
 */
class Document extends ActiveRecord
{
    const THUMBNAILABLE_MIMETYPES = ['image/jpg', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    const RESIZABLE_MIMETYPES = ['image/jpg', 'image/jpeg', 'image/png', 'image/webp'];

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return \Yii::$app->documentable->table_name ?? 'document';
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            ['class' => \yii\behaviors\TimestampBehavior::class],
            ['class' => \yii\behaviors\BlameableBehavior::class],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['title', 'url_master', 'rel_table', 'rel_id'], 'required'],
            [['size', 'rank', 'rel_id', 'copy_group', 'created_at', 'created_by', 'updated_at', 'updated_by'], 'integer'],
            [['rel_table', 'rel_type_tag'], 'string'],
            [['title', 'url_master', 'url_thumb'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'rel_id' => 'Rel ID',
            'rel_table' => 'Rel Type (ID)',
            'rel_type_tag' => 'Rel Type Tag (Type ID)',
            'rank' => 'Rank',
            'title' => 'Title',
            'url_thumb' => 'Url Thumb',
            'url_master' => 'Url Original',
            'size' => 'Size',
            'created_at' => 'Created At',
            'created_by' => 'Created By',
            'updated_at' => 'Updated At',
            'updated_by' => 'Updated By',
        ];
    }

    //=== ACCESSORS
    // GET model linked to a document by
    /**
     * @return string classna
     */
    public function getRelClassName()
    {
        $tablesplit = array_map('ucfirst', explode('_', $this->rel_table));
        $classname = implode('', $tablesplit);
        return "\\app\\models\\${classname}";
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getRelModel()
    {
        // return object
        return $this->hasOne($this->relClassName, ['id' => 'rel_id']);
    }

    //=== EVENTS
    /**
     * before save
     * set the rank to the current number of of rels in this group
     * @inheritdoc
     */
    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            // set the rank (in insert case)
            if ($insert) {
                $this->rank = self::find()->where([
                    'rel_table' => $this->rel_table,
                    'rel_id' => $this->rel_id,
                ])->andFilterWhere([
                    'rel_type_tag' => $this->rel_type_tag
                ])->count();
            }
            return true;
        }
        return false;
    }

    /**
     * delete
     * proper delete also removes ressources from S3
     */
    public function delete()
    {
        // update rank
        if ($this->rank !== null) {
            // decrease all ranks higher than the current one by 1
            $this->moveFromRankTo($this->rank);
        }
        // if a copy, reduce the number of copies
        $copyNb = (null == $this->copy_group) ? 1 : self::find()->where(['copy_group' => $this->copy_group])->count();
        switch ($copyNb) {
        case 2: // remove last copy of original
            $this->updateAll(['copy_group' => null], ['copy_group' => $this->copy_group]);
            break;
        case 1: // removing original (and attached files)
            /** @var DocumentableComponent $docsvc */
            $docsvc = \Yii::$app->documentable;
            // delete the thumbnail if available
            if ($this->url_thumb
            && ($this->url_thumb != $this->url_master)) {
                $docsvc->deleteFile($this->url_thumb);
            }

            $docsvc->deleteFile($this->url_master);
            break;
        default:
        }

        // remove DB record
        return parent::delete();
    }

    //=== SPECIAL
    /**
     * move from rank to
     * move from rank iFrom to iTo (target)
     * C:max O(2)
     * @param int $iFrom rank to move from
     * @param int $iTo rank to move to (if not specified move down rank)
     */
    public function moveFromRankTo($iFrom, $iTo = null)
    {
        // 0 1 2 3 4 5 6 7
        // 0 2[1]3...       2 moved from 2 to 1     -> [ +1 ] for 1 to 2, then set 1
        //   ^
        // 3[0 1 2]4...     3 moved from 3 to 0     -> [ +1 ] for 0 to 3, then set 0
        // ^
        // 0[2 3 4]1 5      1 moved to 4            -> [ -1 ]
        //         ^
        // 0 1[2 3 4 5...]  1 deleted (no iTo)      -> [ -1 ]
        //   ^
        $iTarget = $iTo; // save target from ordering
        $op = '-';
        if (($iTo !== null) && ($iFrom > $iTo)) {
            // ensure iFrom < iTo always
            //swap($iFrom, $iTo);
            $iTmp = $iFrom;
            $iFrom = $iTo;
            $iTo = $iTmp;
            $op = '+';
        }
        // base query
        $paramsBound = [
            'rel_table' => $this->rel_table,
            'rel_id' => $this->rel_id,
            'rank_from' => $iFrom
        ];
        $tn = self::tableName();
        // guarantee we stay under 0
        $sql = "UPDATE `{$tn}` SET `rank`=GREATEST(0, `rank`{$op}1)"
            .' WHERE `rel_table`=:rel_table AND `rel_id`=:rel_id'
            .' AND `rank` IS NOT NULL AND `rank`>=:rank_from';
        // extra filters
        if ($iTo !== null) {
            // add filter to rank iTo  (<= note!)
            $paramsBound['rank_to'] = $iTo;
            $sql .= ' AND `rank`<=:rank_to';
        }
        if ($this->rel_type_tag != null) {
            // to do it yii style and protect params
            $paramsBound['rel_type_tag'] = $this->rel_type_tag;
            $sql .= ' AND `rel_type_tag`=:rel_type_tag';
        }
        \Yii::$app->db->createCommand($sql, $paramsBound)->execute();

        // if a target is specified, update the rank of the target
        if ($iTarget !== null) {
            // finally set rank for the moved one
            $this->rank = $iTarget;

            if ($this->save(false, ['rank'])) {
                return true;
            }
            $this->addError('rank', "Can't assign rank to document:{$this->id}");
            return false;
        }
    }

    //=== AWS S3
    /**
     * get presigned url valid for the next 10 minutes
     * @param bool $returnMaster true for master | false for thumbnail
     * @return ???
     */
    public function getURI($returnMaster = true)
    {
        $filename = $returnMaster ? $this->url_master : $this->url_thumb;
        if ($filename == null) {
            return null;
        }
        /** @var S3Client $s3 */
        /** @var DocumentableComponent $docsvc */
        $docsvc = \Yii::$app->documentable;
        return $docsvc->getURI($filename);
    }

    /**
     * getObject
     * returns the complete object
     * @param bool $returnMaster true for master | false for thumbnail
     */
    public function getObject($returnMaster = true)
    {
        $filename = $returnMaster ? $this->url_master : $this->url_thumb;
        if ($filename == null) {
            return null;
        }
        /** @var S3Client $s3 */
        /** @var DocumentableComponent $docsvc */
        $docsvc = \Yii::$app->documentable;
        return $docsvc->getObject($filename, $this->mimetype);
    }

    /**
     * upload one file given by FS path to s3
     * also generates thumbnail if possible and required
     * (add: encrypt the file)
     * FS
     * @param string filepath - location of file in FS
     * @param string basename - filename, no path, no extension
     * @param string extension - filename extension only
     * @param string mimetype
     * @param integer filesize
     * DB
     * @param integer relId id of model to link to
     * @param string relType type of rel ('article')
     * @param string relTag tag attached to the relationship
     * Options
     * @param Array options as key => value from DocumentableBehavior
     */
    private static function _uploadFile(
        $filepath,
        $mimetype,
        $relId,
        $relType,
        $relTag,
        $options
    ) {
        /** @var DocumentableComponent $docsvc */
        $docsvc = \Yii::$app->documentable;

        $path = \Yii::getAlias($filepath); // `/dir/file.ext`
        $pathParts = pathinfo($path);
        $basename = $pathParts['filename']; // `file`
        $extension = $pathParts['extension']; // `ext`
        $filename = $pathParts['basename']; // `file.ext`

        $now = time();
        $s3prefix = substr(md5("{$filename}{$now}"), 0, 8).'-';

        // MASTER: process image file (if it's an image)
        $docsvc->processImageFile($path, $mimetype, $options);

        $filesizeFinal = filesize($path);

        $thumbnailFilenameFinal = null;

        // THUMBNAIL: process/generate if it's a thumbnail-able image
        if ($options['thumbnail'] ?? false) {
            $thumbnailPath = $docsvc->processImageThumbnail($path, $mimetype, $options);
            if (false !== $thumbnailPath) {
                // upload to bucket / FS, remove temp file
                $thumbnailFilenameFinal = $docsvc->saveFile($thumbnailPath, $mimetype);
            }
        }

        // save master file and remove temp files
        $filenameFinal = $docsvc->saveFile($path, $mimetype);

        if ('image/svg+xml' == $mimetype) {
            // thumbnail and master are the same
            $thumbnailFilenameFinal = $filenameFinal;
        }

        // Create `document`
        $model = new Document([
            'rel_table' => $relType,
            'rel_id' => $relId,
            'rel_type_tag' => $relTag,
            'url_master' => $filenameFinal,
            'url_thumb' => $thumbnailFilenameFinal, // AWS S3 key for thumbnail file
            'title' => $filename,
            'size' => $filesizeFinal, // in Bytes (save to be able to know how much data this user uses)
        ]);
        if (!$model->save() && ($errors = $model->errors)) {
            // throw exception couldn't save document
            $err = empty($errors) ? 'unknown reason' : $errors[0][0];
            throw new yii\db\Exception("Couldn't Save `document` reason:{$err}");
        }
        // success, return document id
        return $model;
    }

    /**
     * uploads one file either from
     * - an Http Request via UploadedFile
     * - or for Console uploads directly
     * uploads a File to a given model
     * - handle zips
     * @param UploadedFile|string $file (UploadeFile or path to file)
     * @param ActiveRecord $model
     * @param string $tag // relation_tag
     * @param array $options
     */
    public static function uploadFileForModel($file, $model, $tag, $options = [])
    {
        /** @var DocumentableComponent $docsvc */
        $docsvc = \Yii::$app->documentable;

        $path = null;
        $mimetype = null;
        if (is_string($file)) {
            // filesystem direct copy
            $filename = pathinfo($file, PATHINFO_BASENAME);
            $path = "{$docsvc->fs_path_tmp}/{$filename}";
            // make a copy of the file to upload to the temp folder
            // so that the original doesn't get deleted after upload
            copy($file, $path);
            $mimetype = FileHelper::getMimeType($path);
        } else {
            // UploadedFile (Yii2 object)
            $path = "{$docsvc->fs_path_tmp}/{$file->baseName}.{$file->extension}";
            $mimetype = $file->type;
            $file->saveAs($path); // guzzle the file
        }

        // do we unzip a zipped file?
        $acceptedZipTypes = ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip', 'application/x-compressed'];
        $unzip = $options['unzip'] ?? false;
        if ((false === $unzip) || !in_array($file->type, $acceptedZipTypes)) {
            // simple single file
            // not a zip
            self::_uploadFile(
                $path,
                $mimetype,
                $model->id,
                $model->tableName(),
                $tag,
                $options
            );
            return;
        }

        // unzip and if unzip is an array, use the array to filter the mimetypes to extract
        // if true extract all
        $zip = new \ZipArchive();
        $res = $zip->open($path);
        // save files to process in order
        if ($res === true) {
            // Unzip the Archive.zip and upload it to s3
            $zipBasePath = "{$docsvc->fs_path_tmp}/unzip";
            $zip->extractTo($zipBasePath);
            $unzipFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                // loop through the archive
                $stat = $zip->statIndex($i);
                $filepath = "{$zipBasePath}/{$stat['name']}"; // "setA/a.txt"
                $filesize = $stat['size'];
                $mimetype = mime_content_type($filepath); // text/plain
                if (($filesize > 0)
                && ((true === $unzip) || (is_array($unzip) && in_array($mimetype, $unzip)))
                ) {
                    $unzipFiles[$filepath] = $mimetype;
                }
            }
            // now sort the files alphabetically
            ksort($unzipFiles, SORT_ASC);
            // and finally save them
            foreach ($unzipFiles as $filepath => $mimetype) {
                // Extractable file, (not directory)
                self::_uploadFile(
                    $filepath,
                    $mimetype,
                    $model->id,
                    $model->tableName(),
                    $tag,
                    $options // options
                );
            }
            // delete zip folder
            FileHelper::removeDirectory($zipBasePath);
            //rmdir($zipBasePath);
        }
        // delete zip file
        // as it won't be done after upload by _uploadFile
        FileHelper::unlink($path);
    }

    /**
     * copy to model
     * this function allows copying to non-Documentable models, @see DocumentableBehavior::copyDocs()
     * it only creates a new Document and specifies a copy_group for all Documents pointing to the same real file
     * @param ActiveRecord $model
     * @param String $tag name
     */
    public function copyToModel($model, $tag = null)
    {
        // if this is not a copied model, create a copy group
        if (null == $this->copy_group) {
            $this->copy_group = $this->id;
            $this->save(false);
        }
        $newDoc = clone $this;
        $newDoc->rel_type_tag = $tag ?? $this->rel_type_tag; // reuse original tag if not specified
        $newDoc->rel_table = $model->tableName();
        $newDoc->rel_id = $model->id;
        $newDoc->id = null;
        $newDoc->isNewRecord = true; // assign a new
        $newDoc->copy_group = $this->copy_group; // copy the group
        $newDoc->save(false);
        return $newDoc;
    }
}//eo-class
