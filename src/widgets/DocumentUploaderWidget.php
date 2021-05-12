<?php

namespace pixium\documentable\widgets;

use Exception;
use Yii;
use yii\widgets\InputWidget;

// use yii\helpers\Html;
// use yii\helpers\Json;

class DocumentUploaderWidget extends InputWidget
{
    /**
     * mimetype to allow
     */
    public $mimetypes = null; // if not set to null, used in priority
    public $allowedFileExtensions = null;
    /**
     * relTypeTag: string - sub relationship type to be attached to the uploaded document
     * this makes possible different types of relationship groups between a given Model and a Document
     * e.g. User PROFILE_IMAGE Documnet, User RESUME_PDF Document...
     */
    public $relTypeTag = null;

    /**
     * if defined, save reltype and uploads in a file
     */
    public $index = null;

    /**
     * default template
     */
    public $template = '{label}{input}';

    /**
     * clear: boolean if true, no preview of previous docs
     */
    public $clear = false;
    /**
     * multiple: boolean|null if defined, use in priority
     */
    public $multiple = null;
    /**
     * replace images on adding an image/images
     */
    public $replace = null;
    /**
     * default maxfile size taken from app (in Kb)
     */
    public $maxFileSize = null;
    /**
     *
     */
    public $pluginOptions = [
        // control display of widget elements
        'showPreview' => true,
        'showCaption' => true,
        'showRemove' => true,
        'showRemoveAll' => false,
        'showUpload' => false, // acts as a save (submit form)
        //'dropZoneEnabled' => true,
        // style buttons
        'browseClass' => 'btn btn-success',
        'uploadClass' => 'btn btn-info',
        'removeClass' => 'btn btn-danger',
        //'removeIcon' => '<i class="fas fa-trash-alt"></i> ',
        // preview type 'image' / 'any'
        //'previewFileType' => 'image',
        'previewFileType' => 'any',
        // 'previewFileIconSettings' => [
        //     'doc' => '<i class="fa fa-file-word-o text-primary"></i>',
        // ],
        // 'previewFileExtSettings' => [ // configure the logic for determining icon file extensions
        //     'doc' => 'function(ext) { return ext.match(/(doc|docx)$/i); }',
        // ],
        'initialPreviewAsData' => true, // important otherwise you'll get the url to the data only
        'maxFileCount' => 5,
        // 'allowedFileTypes' => ['image', 'video'],
        // resize image client side (only with AJAX uploads)
        //'resizeImages' => true,
    ];
    public $pluginEvents = [];
    // 'fileclear' => 'function() { console.log("fileclear"); }',
    // 'filereset' => 'function() { console.log("filereset"); }',

    /**
     * @inheritdoc
     */
    public function init()
    {
        // get params (init options)
        $this->pluginEvents = [
            'fileclear' => new \yii\web\JsExpression('function() { console.log("fileclear"); }'),
            'filesorted' => new \yii\web\JsExpression('function(event,params) { fileUploadFileSort(event,params); }')
        ];
        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        // register assets
        DocumentUploaderAsset::register($this->getView());

        $this->attribute = $this->attribute ?? 'uploads';
        $this->index = null;

        if (!$this->hasModel()) {
            dump("no model given [{$this->attribute}]");
            return;
        }
        $model = $this->model;
        if (!$model->hasMethod('getDocs')) {
            throw new Exception(get_class($model).' needs to implement DocumentableBehavior');
        }
        // attached file should be defined as a property
        if (!($model->hasAttribute($this->attribute) || $model->hasProperty($this->attribute))) {
            throw new Exception(get_class($model)." has no [{$this->attribute}] attribute");
        }
        $form = $this->field->form;
        // set filters
        $acceptMultipleFiles = $this->multiple ?? $model->filter[$this->attribute]['multiple'] ?? false;
        // overwrites initial if accpets only one file and if set in DocumentableBehavior's filters
        $overwriteFilesOnAdd = $this->replace ?? (!$acceptMultipleFiles || ($model->filter[$this->attribute]['replace'] ?? false));
        // mimiTypes accepted, file extensions accepted
        $allowedMimetypes = $this->mimetypes ?? $model->filter[$this->attribute]['mimetypes'] ?? false;
        $maxFileSize = $allowedMimetypes = $this->maxFileSize ?? $model->filter[$this->attribute]['maxsize'] ?? Yii::$app->params['upload_max_size'] ?? 10;
        $allowedFileExtensions = $this->allowedFileExtensions ?? $model->filter[$this->attribute]['extensions'] ?? false;
        // prepare widget's initial state
        $existingDocUrls = [];
        $existingDocConfigs = [];
        $docs = $model->getDocs($this->attribute)->all(); // get docs for given property
        // prepare configuration for
        foreach ($docs as $doc) {
            array_push($existingDocUrls, $doc->getURI());
            array_push($existingDocConfigs, [
                'key' => $doc->id, // pass the id of the document_rel to delete
                'caption' => $doc->title,
                'size' => $doc->size,
                'downloadUrl' => $doc->getURI(true)
            ]);
        }

        // get one file if kartik FileUpload expects one file only
        if (
            !$acceptMultipleFiles
            && null !== ($files = $model->{$this->attribute})
        ) {
            $model->{$this->attribute} = is_array($files) ? array_shift($files) : $files;
        }

        $options = [
            'options' => [
                // limit file types
                //'accept' => 'image/*',
                'accept' => $allowedMimetypes, //$mimetypes, //'image/*,application/pdf',
                // multiple selection
                // !!!crashes if false and array is given
                'multiple' => $acceptMultipleFiles,
            ],
            'pluginOptions' => array_merge($this->pluginOptions, [
                // pass URLs oonly to documents
                'initialPreview' => $existingDocUrls,
                // [
                //     'http://upload.wikimedia.org/wikipedia/commons/thumb/e/e1/FullMoon2010.jpg/631px-FullMoon2010.jpg',
                //     'http://upload.wikimedia.org/wikipedia/commons/thumb/6/6f/Earth_Eastern_Hemisphere.jpg/600px-Earth_Eastern_Hemisphere.jpg'
                // ],
                // pass other config elements (caption, size, key, download-url...)
                // <http://demos.krajee.com/widget-details/fileinput>
                'initialPreviewConfig' => $existingDocConfigs,
                'initialPreviewDownloadUrl' => $existingDocUrls,
                // 'initialCaption' => 'The Moon and the Earth',
                // 'initialPreviewConfig' => [
                //     ['key' => 17, 'caption' => 'Moon.jpg', 'size' => '873727'],
                //     ['key' => 23, 'caption' => 'Earth.jpg', 'size' => '1287883'],
                // ],
                // if set to true (default) remove the exisitng images from the box when adding a new one
                'overwriteInitial' => $overwriteFilesOnAdd,
                'multiple' => $acceptMultipleFiles,
                'allowedFileExtensions' => $allowedFileExtensions,
                'maxFileSize' => $maxFileSize, // Kb add to params
                // delete
                'deleteUrl' => \yii\helpers\Url::to(['document/delete']),
            ]),
            // events
            'pluginEvents' => $this->pluginEvents
        ];

        // change form classes to apply specific styles based on model params
        $formFieldClasses = 'form-group';
        if ($acceptMultipleFiles) {
            $formFieldClasses .= ' fi-multi';
        } elseif ($model->filter[$this->attribute]['unzip'] ?? false) {
            $formFieldClasses .= ' fi-zip';
        }

        // Render Widget (accept multiple widgets)
        echo $form->field($model, "{$this->attribute}[]"
        // .($acceptMultipleFiles ? '[]' : '') // always return an array of files
        , [
            // 'fieldConfig' => ['template' ]
            'template' => $this->template,
            'options' => ['class' => $formFieldClasses]])
            ->widget(\kartik\file\FileInput::classname(), $options)
            ->label(false);
        //DBG
        //dump(['attribute used' => $this->attribute]);
        //dump(['existingDocUrls' => $existingDocUrls]);
    }
}
