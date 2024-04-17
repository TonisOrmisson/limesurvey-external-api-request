<?php

/**
 * Class ExternalApiRequest -
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
            ],
    ];

    const SESSION_KEY = "ExternalApiRequest";


    /* Register plugin on events*/
    public function init() {
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('beforeSurveyPage');
    }



    public function beforeSurveyPage()
    {
        Yii::log("beforeSurveyPage", "trace",  $this->logCategory());
        $this->loadSurvey();
        /** @var LSYii_Application $app */
        $app = Yii::app();
        $data = $this->makeRequest();
        $app->setConfig("ExternalApiPluginData", $data);
    }

    private function makeRequest()
    {
        Yii::log("Trying to make request", "trace",  $this->logCategory());
        if (empty($this->survey)) {
            Yii::log("Missing survey, ending ...", "info",  $this->logCategory());
            return null;
        }

        $this->loadSurveySettings();

        $paramValue = $this->paramValue();
        if (empty($paramValue)) {
            Yii::log("Missing paramValue, nothing to do, ending ...", "info",  $this->logCategory());
            return null;
        }
        $surveyId = $this->survey->primaryKey;

        Yii::log("####". serialize($paramValue), "trace",  $this->logCategory());


        $paramName = trim($this->get("paramName", 'Survey', $surveyId));
        $requestUrl = $this->get("requestUrl", 'Survey', $surveyId);
        $authenticationBearer = $this->get("authenticationBearer", 'Survey', $surveyId);

        $authorization = "Authorization: Bearer " . $authenticationBearer;
        $postRequest = [
            $paramName => $paramValue,
        ];

        // Create curl resource
        Yii::log("Making curl request to $requestUrl", "trace",  $this->logCategory());
        $ch = curl_init();

        // Set URL
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            $authorization,
        ]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postRequest));

        // $output contains output as a string
        $output = curl_exec($ch);

        Yii::log("Closing curl request", "trace",  $this->logCategory());
        Yii::log("request result:" . $output, "trace",  $this->logCategory());
        // Close curl resource
        curl_close($ch);

        return json_decode($output, true);
    }

    /**
     * @return string|null
     */
    private function paramValue()
    {
        $surveyId = $this->survey->primaryKey;
        $paramName = trim($this->get("paramName", 'Survey', $surveyId));
        if (empty($paramName)) {
            return null;
        }
        Yii::log("looking value for $paramName", "trace",  $this->logCategory());

        /** @var CHttpSession $session */
        $session = Yii::app()->session;
        $key = $this->sessionKey()."::paramValue";
        if(isset($_GET['newtest']) and isset($_GET['newtest']) == "Y") {
            unset($session[$key]);
        }
        if(isset($_GET[$paramName]) && !empty($_GET[$paramName])) {
            $value = trim(strval($_GET[$paramName]));
            $session[$key] =  $value;
        }
        if (!isset($session[$key])) {
            return null;
        }

        return strval($session[$key]);

    }



    private function sessionKey()
    {
        return self::SESSION_KEY."::".$this->survey->primaryKey;
    }

    private function loadSurvey()
    {
        Yii::log("Loading survey", "trace",  $this->logCategory());

        $event = $this->event; // beforeSurveyPage;
        $possibleSurveyIdParameterNames = ['surveyId', 'survey', 'surveyid'];

        foreach ($possibleSurveyIdParameterNames as $possibleSurveyIdParameterName) {
            $surveyId = $event->get($possibleSurveyIdParameterName);
            if(!empty($surveyId)) {
                Yii::log("Found surveyId:" . $surveyId, "trace",  $this->logCategory());
                break;
            }
        }
        if(empty($surveyId)) {
            Yii::log("SurveyId not found:" . $surveyId, "trace",  $this->logCategory());
            return;
        }

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
            Yii::log("Got empty survey:$surveyId", "info",  $this->logCategory());
            return;
        }
        Yii::log("Creating a survey from array", "trace",  $this->logCategory());
        $this->survey = (new Survey());
        $this->survey->attributes = $surveyArray;

    }



    /**
     * This event is fired by the administration panel to gather extra settings
     * available for a survey.
     * The plugin should return setting meta-data.
     */
    public function beforeSurveySettings()
    {
        Yii::log("Survey settings page load beforeSurveySettings", "trace",  $this->logCategory());
        $this->loadSurvey();
        $this->loadSurveySettings();
    }

    private function loadSurveySettings(){
        Yii::log("Trying to load survey settings from global", "trace",  $this->logCategory());
        if(empty($this->survey)) {
            Yii::log("Survey not set, skipping", "trace",  $this->logCategory());
            return;

        }

        $event = $this->event;
        $globalSettings = $this->getPluginSettings(true);

        $surveySettings = [];
        foreach ($globalSettings as $key => $setting) {
            $currentSurveyValue = $this->get($key, 'Survey', $this->survey->primaryKey);
            $surveySettings[$key] = $setting;
            if(!empty($currentSurveyValue)) {
                $surveySettings[$key]['current'] = $currentSurveyValue;
            }
        }

        Yii::log("Setting survey settings", "trace",  $this->logCategory());
        $event->set("surveysettings.{$this->survey->primaryKey}", [
            'name' => get_class($this),
            'settings' => $surveySettings,
        ]);

        // always get and save defaults if they are missing
        $paramName = trim($this->get("paramName", 'Survey', $this->survey->primaryKey));
        Yii::log("Using paramName '$paramName' for request", "trace",  $this->logCategory());
        if(empty($paramName)) {
            Yii::log("No param name loading defaults", "trace",  $this->logCategory());
            $this->loadDefaultSettings();
        }
        Yii::log("Done loading survey settings", "info",  $this->logCategory());

    }


    public function newSurveySettings()
    {
        $event = $this->event;

        foreach ($event->get('settings') as $name => $value) {
            $this->set($name, $value, 'Survey', $event->get('survey'));
        }
    }

    private function loadDefaultSettings() {
        $event = $this->event;
        $surveyId = $this->survey->primaryKey;
        $settings = [];
        Yii::log("Loading settings from default", "trace", $this->logCategory());
        $globalSettings = $this->getPluginSettings(true);

        foreach ($globalSettings as $key => $setting) {
            $settings[$key] = $setting['current'];
        }

        $event->set('settings', $settings);
        $event->set('survey', $surveyId);
        $this->newSurveySettings();

    }

    private function logCategory()
    {
        return "andmemasin\\ExternalApiRequest";
    }

}
