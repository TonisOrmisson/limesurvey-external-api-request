<?php

/**
 * Class ExternalApiRequest -
 * URL parameter
 * @author Tõnis Ormisson <tonis@andmemasin.eu>
 */
class ExternalApiRequest extends PluginBase {

    protected $storage = 'DbStorage';
    static protected $description = 'A plugin to make API requests to external systems and push results to interview template ';
    static protected $name = 'External API requests';


    /** @var Survey */
    private $survey;

    protected $settings = [
            'enabled' => [
                'type' => 'boolean',
                'label' => 'Enable plugin for survey',
                'default'=>false,
            ],
            'requestUrl' => [
                'type' => 'string',
                'label' => 'External API request URL',
                'default' => "https://api.example.com/get-smth",
            ],
            'authenticationBearer' =>[
                'type' => 'string',
                'label' => 'External API request authentication Bearer header value',
                'default' => 'my-auth-bearer-token',
            ],
            'paramName' => [
                'type' => 'string',
                'label' => 'an input URL parameter to be injected to API request',
                'default' => 'username',
            ]
    ];

    const SESSION_KEY = "ExternalApiRequest";


    /* Register plugin on events*/
    public function init() {
        $this->subscribe('afterFindSurvey');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('beforeSurveyPage');
    }



    public function beforeSurveyPage()
    {

        $data = $this->makeRequest();
        Yii::app()->params['surveyThemeData'] = $data;
    }

    private function makeRequest()
    {
        $this->loadSurvey();

        $userName = $this->userName();

        if (empty($this->survey) or empty($userName)) {
            return null;
        }


        $surveyId = $this->survey->primaryKey;
        $paramName = trim($this->get("paramName", 'Survey', $surveyId));
        $requestUrl = $this->get("requestUrl", 'Survey', $surveyId);
        $authenticationBearer = $this->get("authenticationBearer", 'Survey', $surveyId);

        $authorization = "Authorization: Bearer " . $authenticationBearer;
        $postRequest = [
            $paramName => $userName
        ];

        // Create curl resource
        $ch = curl_init();

        // Set URL
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            $authorization
        ]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postRequest));

        // $output contains output as a string
        $output = curl_exec($ch);

        // Close curl resource
        curl_close($ch);

        return json_decode($output, true);
    }

    /**
     * @return string|null
     */
    private function userName()
    {
        $surveyId = $this->survey->primaryKey;
        $paramName = trim($this->get("paramName", 'Survey', $surveyId));
        if (empty($paramName)) {
            return null;
        }


        /** @var CHttpSession $session */
        $session = Yii::app()->session;
        $key = $this->sessionKey()."::username";
        if(isset($_GET[$paramName]) && !empty($_GET[$paramName])) {
            $userName = trim(strval($_GET[$paramName]));
            $session[$key] =  $userName;
        }
        if (!isset($session[$key])) {
            return null;
        }

        return strval($session[$key]);

    }


    public function afterFindSurvey() {
        $this->loadSurvey();
        if (empty($this->survey)) {
            return;
        }
        $surveyId = $this->survey->primaryKey;

        $pluginEnabled = boolval($this->get("enabled", 'Survey', $surveyId));
        if (!$pluginEnabled) {
            return;
        }

        $paramName = $this->get("paramName", 'Survey', $surveyId);
        if (empty($paramName)) {
            return;
        }


    }

    private function sessionKey()
    {
        return self::SESSION_KEY."::".$this->survey->primaryKey;
    }

    private function loadSurvey()
    {
        $event = $this->event;
        $surveyId = $event->get('surveyid');

        /**
         * NB need to do it without find() since the code at hand is itself run
         * after find() resulting in infinite loop
         */
        $query = Yii::app()->db->createCommand()
            ->select('*')
            ->from(Survey::model()->tableName())
            ->where('sid=:sid')
            ->bindParam(':sid', $surveyId, PDO::PARAM_STR);
        $surveyArray = $query->queryRow();

        if (empty($surveyArray)) {
            return;
        }
        $this->survey = (new Survey());
        $this->survey->attributes = $surveyArray;

    }



    /**
     * This event is fired by the administration panel to gather extra settings
     * available for a survey.
     * The plugin should return setting meta data.
     */
    public function beforeSurveySettings()
    {
        $event = $this->event;
        $globalSettings = $this->getPluginSettings(true);

        $surveySettings = [];
        foreach ($globalSettings as $key => $setting) {
            $currentSurveyValue = $this->get($key, 'Survey', $event->get('survey'));
            $surveySettings[$key] = $setting;
            if(!empty($currentSurveyValue)) {
                $surveySettings[$key]['current'] = $currentSurveyValue;
            }

        }
        $event->set("surveysettings.{$this->id}", [
            'name' => get_class($this),
            'settings' => $surveySettings,
        ]);
    }


    public function newSurveySettings()
    {
        $event = $this->event;
        foreach ($event->get('settings') as $name => $value)
        {
            $this->set($name, $value, 'Survey', $event->get('survey'));
        }
    }

}
