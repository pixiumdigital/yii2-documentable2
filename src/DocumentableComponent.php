<?php
namespace pixium\documentable;

use Aws\Exception\AwsException;
use Aws\RetryMiddleware;
use Aws\S3\S3Client;
use Exception;
use yii\base\Component;
use yii\helpers\FileHelper;
use yii\helpers\Html;
use yii\helpers\VarDumper;

/**
 *   Add to config.components
 *   'documentable' => [
 *       'class' => 'pixium\documentable\DocumentableComponent',
 *       'config' => [
 *          'table_name' => 'document' // database table name used for docments
 *          'aws_s3_config' => [ // if defined wil use s3 bucket instead of Filesystem
 *               'bucket_name' => 'mybucket'
 *               'version' => 'latest',
 *               'region' => 'default',
 *               'credentials' => [
 *                   'key' => 'none',
 *                   'secret' => 'none'
 *               ],
 *               // call docker defined localstack API endpoint for AWS services
 *               // avoid lib curl issues on bucket-name.ENDPOINT resolution
 *               // <https://github.com/localstack/localstack/issues/836>
 *               'use_path_style_endpoint' => true,
 *          ],
 *          'fs_path' => '/tmp/assets', // path to save folder
 *          'fs_path_tmp' => '/tmp', // path to temp save folder
 *          'image_options' => [ // can be overwritten at DocumentableBehaviour level
 *              'max_image_size' => max image size (height or width) (default = 1920)
 *              'quality' => jpeg and webp quality (default = 85)
 *              'jpeg_quality' => jpeg quality uses quality if not set,
 *              'webp_quality' => webp quality uses quality if not set,
 *              'png_compression_level' => png compression (default = 6), 1 quality - 9 small size
 *              'thumbnail' => [
 *                  'default' => null  url to default image
 *                  'default_icon' => '<i class="fa fa-file-image-o fa-3x" aria-hidden="true"></i>'
 *                  'type' => 'png' (default = null: copy from parent )
 *                  'square' => 150 (default)
 *                  'width' => 200, 'height' => 100,
 *                  'crop' => true (crop will fit the smaller edge in the defined box)
 *                  'background_color' => '000',
 *                  'background_alpha' => 0,
 *              ]
 *          ],
 *       ]
 *   ],
 */
class DocumentableComponent extends Component
{
    const THUMBNAILABLE_MIMETYPES = ['image/jpg', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    const RESIZABLE_MIMETYPES = ['image/jpg', 'image/jpeg', 'image/png', 'image/webp'];

    const PNG_COMPRESSION = 6;
    const IMG_QUALITY = 85;

    /** @var S3Client $s3 */
    public $s3 = null; // 'common\services\AWSComponent'

    /** @var string $s3_bucket_name */
    public $aws_s3_config = null;

    /** @var string $$s3_bucket_name name of the bucket */
    public $s3_bucket_name = 'bucket';

    /** @var string $fs_path path to upload folder
     * the easiest way to reach your docs is to create a simlink in the {frontend|backend}/web folder:
     *   ln -s /var/tmp ./frontend/web/tmp
     * and enable simlink navigation in Nginx:
     *   disable_symlinks off;
     */
    public $fs_path = '/tmp/upload'; // path

    /** @var string $fs_path path to temp upload folder */
    public $fs_path_tmp = '/tmp'; // path

    /** @var string $table_name document table name */
    public $table_name = 'document';

    /** @var HasherInterface $hasher */
    private $hasher = null;
    public $hasher_class_name = 'pixium\documentable\models\Hasher';

    // TODO:
    /**
     * image options []
     *      max_image_size      default 1920
     *
     * ]
     */
    public $image_options = [];

    // unadulterated config, overwritten by reflection
    public $config = [
    ];

    public function __construct($config = [])
    {
        // ... initialization before configuration is applied
        // use reflection
        // will map $config keys to attributes
        // e.g. 'version' => 1      mapped to $this->version
        parent::__construct($config);
    }

    /**
     * @param string $path to test
     * @param string $label for the path (upload, upload tmp...)
     * @throws Exception if path is not found, not a dir or not writable
     */
    private function validateFS($path, $label)
    {
        if (!is_dir($path)) {
            throw new Exception("Documentable: '{$path}' {$label} folder not found");
        }
        if (!is_writable($this->fs_path_tmp)) {
            throw new Exception("Documentable: '{$path}' {$label} temp upload folder not writable");
        }
    }

    /**
     * @return bool true: if the component is set with S3
     */
    public function getUsesS3()
    {
        return null !== $this->s3;
    }

    public function init()
    {
        parent::init();

        // dump($this);
        // die;

        if (null !== $this->aws_s3_config) {
            if ($name = $this->aws_s3_config['bucket_name'] ?? false) {
                $this->s3_bucket_name = $name;
                // remove bucket name from the config before passing it to S3Client
                unset($this->aws_s3_config['bucket_name']);
            }
            // create bucket handler
            $this->s3 = new \Aws\S3\S3Client($this->aws_s3_config);

            // validate bucket existence
            if (!$this->s3->doesBucketExist($this->s3_bucket_name)) {
                throw new Exception("Documentable: S3 bucket name '{$this->s3_bucket_name}' not found");
            }
        } else {
            // use FS - validate file storage path
            $this->validateFS($this->fs_path, 'upload');
        }

        // dump(['config' => $this->config, 'a' => 'b']);
        // die;
        // set hasher
        $this->hasher = new $this->hasher_class_name();

        //  validate temp folder
        $this->validateFS($this->fs_path_tmp, 'temporary upload');
    }

    /**
     * rotate, resize and recompress file if an image and can be processed
     * @param string $path
     * @param string $mimetype
     * @return bool true if processed
     */
    public function processImageFile($path, $mimetype, $imageOptions = [])
    {
        // if Imagine is not included, don't resize
        if (!class_exists('\yii\imagine\Image')) {
            return false;
        }

        // TODO: test if imagine available
        if (!in_array($mimetype, self::RESIZABLE_MIMETYPES)) {
            return false;
        }

        $options = array_merge_recursive($this->image_options, $imageOptions);

        // resize
        $max = $options['max_image_size'] ?? 1920;
        $image = \yii\imagine\Image::resize($path, $max, $max);

        // re-orientate
        try {
            $exif = exif_read_data($path);
            // handle EXIF rotation
            switch ($exif['Orientation'] ?? 0) {
            case 3: $image->rotate(180); break;
            case 6: $image->rotate(90); break;
            case 8: $image->rotate(-90); break;
            default: // don't rotate
            }
        } catch (Exception $e) {
            // simply don't reorientate
        }

        // save image to $filePath
        // recompress
        $image->save($path, $this->_getQuality($imageOptions, false));
        return true;
    }

    /**
     * get quality for image processing
     * @param Array $imageOptions to get the quality from
     * @param bool $isForThumbnail
     * @return Array Imagine rady
     */
    private function _getQuality($options, $isForThumbnail = false)
    {
        $options = array_merge_recursive($isForThumbnail ? ($this->image_options['thumbnail'] ?? []) : $this->image_options, $options);
        $quality = $options['quality'] ?? self::IMG_QUALITY;
        return [
            'quality' => $quality,
            'jpeg_quality' => $options['jpeg_quality'] ?? $quality,
            'jpeg_quality' => $options['webp_quality'] ?? $quality,
            'png_compression_level' => $options['png_compression_level'] ?? intval((100 - $quality) / 10),
        ];
    }

    /**
     * rotate image then create a png thumbnail
     * @param string $filepathIn
     * @param string $filepathOut
     * @param string $mimetype
     * @return string|false filepath if processed
     */
    public function processImageThumbnail($filepath, $mimetype = null, $imageOptions = [])
    {
        $path = \Yii::getAlias($filepath);
        if (null == $mimetype) {
            $mimetype = FileHelper::getMimeType($path);
        }

        // if Imagine is not included, don't resize
        if (!class_exists('\yii\imagine\Image')) {
            return false;
        }
        // if the file is not an image or not a resizable one, exit
        if (!in_array($mimetype, self::THUMBNAILABLE_MIMETYPES)) {
            return false;
        }

        $options = array_merge_recursive($this->image_options, $imageOptions);
        $thumbnailOptions = $options['thumbnail'] ?? [];

        $pathParts = pathinfo($path);
        $basename = $pathParts['filename'];
        // extract thumbnail options
        $extension = $thumbnailOptions['type'] ?? $pathParts['extension']; // use forced if given, else same as original
        $wmax = $thumbnailOptions['width'] ?? $thumbnailOptions['square'] ?? 150;
        $hmax = $thumbnailOptions['height'] ?? $wmax;
        $crop = $thumbnailOptions['crop'] ?? false;
        $bgColor = $thumbnailOptions['background_color'] ?? '000';
        $bgAlpha = $thumbnailOptions['background_alpha'] ?? 0;
        $thumbnailPath = "{$this->fs_path_tmp}/{$basename}.thumb.{$extension}";

        // resize
        \yii\imagine\Image::$thumbnailBackgroundColor = $bgColor;
        \yii\imagine\Image::$thumbnailBackgroundAlpha = $bgAlpha;
        \yii\imagine\Image::thumbnail(
            $path,
            $wmax,
            $hmax,
            //\Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND = crop
            $crop ? \Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND : \Imagine\Image\ImageInterface::THUMBNAIL_INSET,
        )->save($thumbnailPath, $this->_getQuality($imageOptions, true));
        return $thumbnailPath;
    }

    /**
     * saves file on FS or S3
     * @param string $filepath src (path to file)
     * @param string $mimetype
     * @return string filename used
     */
    public function saveFile($path, $mimetype = null)
    {
        $filename = pathinfo($path, PATHINFO_BASENAME);
        $now = time();
        $s3prefix = substr(md5("{$filename}{$now}"), 0, 8).'-';
        $s3filename = "{$s3prefix}{$filename}";
        if (null !== $this->s3) {
            // move it to the S3 bucket
            $s3FileOptions = [
                'Bucket' => $this->s3_bucket_name,
                'Key' => $s3filename
            ];

            $result = $this->s3->putObject(array_merge($s3FileOptions, [
                'SourceFile' => $path,
                // needed for SVGs
                'ContentType' => $mimetype,
                'Metadata' => [
                    'version' => '1.0.0'
                ]
            ]));
            // poll object until it is accessible
            $this->s3->waitUntil('ObjectExists', $s3FileOptions);
            // ERASE tmp thumbnail file
            FileHelper::unlink($path);
            return $s3filename;
        }
        // FS move
        rename($path, "{$this->fs_path}/{$s3filename}");
        return $s3filename;
    }

    /**
     * delete file, if not found ignore
     * @param string $filename target (e.g. cca3dfd5-lasagna5.jpg)
     * @return bool true if deleted
     */
    public function deleteFile($filename)
    {
        try {
            if (null !== $this->s3) {
                $cmd = $this->s3->deleteObject([
                    'Bucket' => $this->s3_bucket_name,
                    'Key' => $filename,
                    // 'VersionId' => 'string',
                ]);
            } else {
                //FS
                $path = "{$this->fs_path}/{$filename}";
                FileHelper::unlink($path);
            }
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * @param string $filename
     * @return string URI to file
     */
    public function getURI($filename, $options = [])
    {
        if (null !== $this->s3) {
            $cmd = $this->s3->getCommand('GetObject', [
                'Bucket' => $this->s3_bucket_name,
                'Key' => $filename,
            ]);

            $request = $this->s3->createPresignedRequest($cmd, '+20 minutes');

            // Get the actual presigned-url
            return (string) $request->getUri();
        }
        // USE FS
        return "{$this->fs_path}/{$filename}";
    }

    /**
     * @param string $filename
     * @return mixed the file
     */
    public function getObject($filename, $mimetype, $options = [])
    {
        if (null !== $this->s3) {
            //Creating a presigned URL
            $result = $this->s3->getObject([
                'Bucket' => $this->s3_bucket_name,
                'Key' => ($filename),
            ]);
            // Display the object in the browser.
            //header("Content-Type: {$result['ContentType']}");
            return $result['Body'] ?? null;
        }
        // USE FS
        return file_get_contents($filename);
    }

    /**
     * return default thumbnail defined on component
     * @return string html for default thumbnail
     */
    public function getThumbnailDefault($options = [])
    {
        $thumbnailOptions = $this->image_options['thumbnail'] ?? [];
        $imgUrl = $thumbnailOptions['default'] ?? null;
        if (null != $imgUrl) {
            return Html::img($imgUrl, $options);
        }
        // go for icons
        $icon = $thumbnailOptions['default_icon'] ?? '<i class="fa fa-file-image-o fa-3x" aria-hidden="true"></i>';
        return Html::tag('div', $icon, $options);
    }
}
