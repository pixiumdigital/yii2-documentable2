# Documentable

## Important

### Changes

- Release v2.x
  Version `2.x` comes with a change of architecture. The `DocumentRel` class was merged with  `Document`
  A migration is provided to update Document from DocumentRel and get rid of the rel table.
- Release v3.x
  refer to [README.V3.md](README.V3.md)



## Add to Composer

Add this repository to your composer.json file

```
"repositories": [
        {
           ...
        },
        {
            "type": "vcs",
            "url": "https://github.com/pixiumdigital/yii2-documentable"
        }
    ],
```

Add the package to the require list
```
"pixium/yii2-documentable": "2.0"
```

or, for the v3 (wip)

```
"pixium/yii2-documentable": "dev-master_v3"
```



## Migrations 

Add to `config/console.php` (Yii2 basic)  or `console/main.php` (Yii2 advanced)

Add this for the migration path to be recognized y `yii migrate` 
If you plan to use your own table with a different name, copy the table structure provided by the migrations in the `@vendor/pixium/yii2-documentable/migrations` folder

```php
'controllerMap' => [
  'migrate'=>[
    'class'=>'yii\console\controllers\MigrateController',
    'migrationLookup'=>[
      '@vendor/pixium/yii2-documentable/migrations',
      '@app/migrations'
    ]
  ]
],
```

or manually run 

on Yii Basic:

```sh
# Yii2 Basic
./yii migrate/up -p @app/vendor/pixium/yii2-documentable/migrations
# Yii2 Advanced
./yii migrate/up -p vendor/pixium/yii2-documentable/migrations
```



### You already have a `document` table in your stack!

> Available only with v3

**Don't run the default migrations**. Make a copy, of the files in  `vendor/pixium/yii2-documentable/migrations`  to your project's migration folder, rename the table `document` to `my_document_table`. In the component defintition specify:

```php
//...
'documentable' => [
  'table_name' => 'my_document_table',
]
```



## Yii2 setup

### With AWS S3 access

The Documentable component now includes the s3 bucket handler directly. To use localstack set your component like this:

```php
'documentable' => [
  'class' => 'pixium\documentable\DocumentableComponent',
  'aws_s3_config' => [
    'bucket_name' => 'my-buket-name',   // DEFINE YOUR BUCKET NAME HERE
    'endpoint' => getenv('AWS_ENDPOINT') ?: 'http://localstack:4572'
  ],
],
```

for production:

```php
'documentable' => [
  'class' => 'pixium\documentable\DocumentableComponent',
  'aws_s3_config' => [
    // DEFINE YOUR BUCKET NAME HERE
    'bucket_name' => getenv('AWS_S3_BUCKET_NAME'),
    'version' => 'latest',
    'region' => getenv('AWS_REGION'),
    'credentials' => [
      'key' => getenv('AWS_KEY'),
      'secret' => getenv('AWS_SECRET')
    ],
    // call docker defined localstack API endpoint for AWS services
    // avoid lib curl issues on bucket-name.ENDPOINT resolution
    // <https://github.com/localstack/localstack/issues/836>
    'use_path_style_endpoint' => true,
  ],
],
```

> The values of `AWS_REGION`, `AWS_KEY`, `AWS_SECRET` and `AWS_ENDPOINT` should be set in `.env` or in the `docker-compose.yml` file (section environment)



### With FS storage

no bucket? You want to put it all on a FS volume?

 ```php
 'components' => [
   'documentable' => [
     'class' => 'pixium\documentable\DocumentableComponent',
     // ALT CONFIG using FILESYSTEM
     'fs_path' => '/tmp/uploads',
   ],
 ]
 ```



### Image defaults

set the image config params

```php
   'documentable' => [
     // ...
     'image_config' => [
        'upload_max_size' => 500, // max upload size for image in Kilobytes
        'max_image_size' => 1920, // 1920x1920
        // thumbnail params
        'thumbnail_size' => ['width' => 200, 'height' => 200],
        'thumbnail_background_color' => 'FFF', // or #AABBCC02 = RGBA
        'thumbnail_background_alpha' => 0, // 0 to 100
        'thumbnail_type' => 'png',       
     ]
   ]
```





### Enable DocumentUploaderWidget

you'll need to add a custom controller to the controller map to make calls to `/document/action` possible.

```sh
// add custom controller route to have the whole Document module as a bundle
'controllerMap' => [
  'document' => 'pixium\documentable\controllers\DocumentController',
],
```

this is critical to be able to **delete** documents from the DocumentUploaderWidget. 



## Using DocumentableBehavior

### In the Model class

```php
use \pixium\documentable\behaviors\DocumentableBehavior;

class MyClass extends \yii\db\ActiveRecord
	// property for file attachment(s)
	public $images;

	public function behaviors()
  {
  	return [
      [ // Documentable Behavior allows attachment of documents to current model
        'class' => DocumentableBehavior::className(),
        'filter' => [
          'images' => [ // this is the default 'tag' since v2.2.1
            //'tag' => 'MYCLASS_IMAGE', // optional now
            //'unzip' => false,
            'multiple' => true,
						'replace' => false,
            'thumbnail' => true,
            // 
            'mimetypes' => 'image/jpeg,image/png',
            'extensions' => ['png','jpg'],
          ]
        ]
      ],
    ];
  }

```

note the key in the behavior is the public property `images` defined above.

To get a document attached to a model

```php
$model = MyClass::findOne($index);

return ($doc1 = $model->getDocs('images')->one())
  ? $doc1->getS3Url(true) // true for master, false for thumbnail
  : Url::to('/img/feature_image_default.svg');

foreach ($model->getDocs('images')->all() as $doc) {
  echo Html::img($doc->getS3Url(true));
}

```

 

### Behavior's options

- `tag` the name that identifies the relationship between a Document and the Owning model. ('AVATAR_IMAGE', 'RESUME', 'COMIC_PAGE'…). As mentioned above, optional since `v2.2.1` the attribute name will be used instead.
- `unzip` a boolean/string to process zip files. If set to true/NAME, each file extracted will be attached instead of the zip itself.
- `multiple` a boolean to specify whether multiple Documents can be attached under the given tag.
- `replace` a boolean to specify the default `multiple` behavior when adding new files.
- `thumbnail` a boolean to specifiy if images given should have a thumbnail created.
- `mimetypes` a csv of mimetypes (accepts meta image/*) to be accepted by the uplaoder widget.
- `extensions` extensions to filter on top of mimetype. 



## Using the uploader Widget

in your view simply add

```php
use \pixium\documentable\widgets\DocumentUploaderWidget;

echo $form->field($model, 'images')->widget(DocumentUploaderWidget::className());

```

where `images` is the property handled by the behavior.



## Troubleshooting

### Resizing after upload

using *imagick*, *gd2* or *gmagik* 

<https://stackoverflow.com/questions/5282072/gd-vs-imagemagick-vs-gmagick-for-jpg>

requires new installs on the **php** docker container.

```dockerfile
RUN apt-get update -y && apt-get install -y libpng-dev

RUN docker-php-ext-install gd
```

then rebuild with

```sh
docker-compose build
# or 
docker-compose build --no-cache
```

**ERROR:** still problems with `/tmp/<anything>`

### Nginx: 413 – Request Entity Too Large

>  see <https://www.cyberciti.biz/faq/linux-unix-bsd-nginx-413-request-entity-too-large/>

#### Nginx configuration

To fix this issue edit your *nginx.conf*. 

```nginx
client_max_body_size 2M;
```

#### PHP configuration (optional)

Your php installation also put limits on upload file size. Edit php.ini and set the following directives

in PHP-FPM (docker image `php:7.2-fpm`), the config files are in `/usr/local/etc/php-fpm.d` so all that has to be done is to map a new `config.ini` file there:

```yaml
php:
	volumes:
    - ./:/app
    #  add a php.ini config
    - ./scripts/env.local/php.ini:/usr/local/etc/php/conf.d/specific.ini:cached
```

in this specific config.ini add

```ini
;This sets the maximum amount of memory in bytes that a script is allowed to allocate
memory_limit = 256M

;The maximum size of an uploaded file.
upload_max_filesize = 32M

;Sets max size of post data allowed. This setting also affects file upload. To upload large files, this value must be larger than upload_max_filesize
post_max_size = 64M
```

limit per route (API endpoint) <https://levelup.gitconnected.com/using-nginx-to-limit-file-upload-size-in-react-apps-4b2ce0e444c2>

### lib zip

```sh
sudo yum install zip
sudo install php7.2-zip
#on debian simply php-zip
sudo yum install libzip-dev 
```

### restart all

```sh
sudo service php-fpm restart
sudo service nginx restart
```


