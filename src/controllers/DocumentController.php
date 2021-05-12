<?php
/**
 * controller to edit document relationships
 * 2019-08-19 Lio @ Pixium Digital Pte Ltd
 */
namespace pixium\documentable\controllers;

use \yii\web\BadRequestHttpException;
use \yii\web\NotFoundHttpException;
use \yii\web\Controller;
use \yii\filters\AccessRule;
use \yii\filters\AccessControl;
use pixium\documentable\models\Document;

/**
 * Replace with your own controller if you need a differnt behavior
 */
class DocumentController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            // 'hashToId' => [ // add `id` from `hash` to _GET from
            //      'class' => \app\components\HashableBehavior::className() //Modify the path to your real behavior class.
            // ]
            // ,
             'access' => [
                'class' => AccessControl::class,
                // We will override the default rule config with the new AccessRule class
                'ruleConfig' => ['class' => AccessRule::class],
                'rules' => [
                    [
                        'actions' => [
                            'delete',
                            'move-rank',
                        ],
                        'allow' => true,
                        'roles' => ['@']
                    ],
                    [
                        'actions' => [
                            'get',
                            'get-thumbnail',
                        ],
                        'allow' => true,
                        // 'roles' => ['*'],
                    ]
                ],
            ],
        ];
    }

    /**
     * delete Document
     * [AJAX]
     * @param number [POST]id document id
     * @param number key document id (bootstrap fileinput format)
     * @return json result
     * @throws yii\web\BadRequestHttpException if args not given
     */
    public function actionDelete()
    {
        if (\Yii::$app->request->isAjax) {
            if (($id = \Yii::$app->request->post('key')) != null) {
                \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
                $model = $this->findModel($id);
                if (!$model->delete()) {
                    return ['error' => ['msg' => "Document {$id} couldn't be deleted."]];
                }
                return ['success' => true];
            }
            return ['error' => ['msg' => 'no document id given.']];
        }

        //__TODO__ isMine
        if (!\Yii::$app->request->isPost
        || (($id = \Yii::$app->request->post('key')) != null)) {
            // id found in _POST, proceeed to delete
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            $model = $this->findModel($id);
            if (!$model->delete()) {
                return ['error' => ['msg' => "Document {$id} couldn't be deleted."]];
            }
            return ['success' => true];
        }
        // not post or no key
        throw new BadRequestHttpException('no POST[key].');
    }

    /**
     * change document rel rank
     * [AJAX]
     * @param number [POST]id document_rel id
     * @param number [POST]i_before index before move
     * @param number [POST]i_after index after move (current pos)
     * @return json result
     * @throws yii\web\BadRequestHttpException if args not given
     */
    public function actionMoveRank()
    {
        if (\Yii::$app->request->isAjax) {
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            $id = \Yii::$app->request->post('id') ?? null;
            $iBefore = \Yii::$app->request->post('i_before') ?? null;
            $iAfter = \Yii::$app->request->post('i_after') ?? null;
            if (($id !== null)
            && ($iBefore !== null)
            && ($iAfter !== null)) {
                // all is set, try to get a documentRel
                if (($model = Document::findOne($id)) !== null) {
                    // found, move it
                    $model->moveFromRankTo($iBefore, $iAfter);
                    return ['success' => true];
                }
                return ['error' => ['msg' => "Document {$id} couldn't be moved."]];
            }
            return ['error' => ['msg' => 'no document id given.']];
        }
        throw new BadRequestHttpException('ajax aonly');
    }

    // === PUBLIC ACTIONS
    /**
     * get document
     * returns binary document to avoid CORS Policy issues.
     * brifge to S3
     * @param bool $thumb (defualt = false) return thumbnail if available
     * @throws yii\web\BadRequestHttpException if args not given
     */
    public function actionGetThumbnail($hash)
    {
        $id = $this->documentable->hasher::h2id($hash);
        if (null !== $id) {
            if (null !== ($doc = Document::findOne($id))) {
                $bin = $doc->getObject(false);
                // get the mimeType dynamically
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($bin);
                // set the respponse
                $response = \Yii::$app->getResponse();
                $response->headers->set('Content-Type', $mimeType);
                $response->format = \yii\web\Response::FORMAT_RAW;
                return $bin; // get thumbnail
            }
        }
        throw new NotFoundHttpException("Document not found {$id}");
    }

    /**
     * Finds the model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Model the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Document::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException('The requested page does not exist.');
    }
}
