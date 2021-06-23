# limesurvey-external-api-request

A LimeSurvey plugin to make API requests to external systems and inject the result to an interview


# Usage
## 1 Install 

### Via console

Change to LS plugins folder:
```
$ cd /your/limesurvey/path/plugins
```
Use git to clone into folder `ExternalApiRequest`:
```
$ git clone https://github.com/TonisOrmisson/limesurvey-external-api-request.git ExternalApiRequest
```


## 2 Activate plugin

## 3 Set default settings:
Default settings can be overridden in survey settings.

The plugin is disabled by default for surveys. 

## 4 configure survey level settings (if needed)

## 5 Use in Twig

Api request will be made on the plugin event 'beforeSurveyPage' and the API request result is 
injected into `LSYII_Application->configuration['ExternalApiPluginData']`

The result may be used in twig survey templates via `{{ getConfig("ExternalApiPluginData") }}`
