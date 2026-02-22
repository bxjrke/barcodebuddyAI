<?php

/**
 * Barcode Buddy for Grocy
 *
 * PHP version 7
 *
 * LICENSE: This source file is subject to version 3.0 of the GNU General
 * Public License v3.0 that is attached to this project.
 *
 * @author     Marc Ole Bulling
 * @copyright  2019 Marc Ole Bulling
 * @license    https://www.gnu.org/licenses/gpl-3.0.en.html  GNU GPL v3.0
 * @since      File available since Release 1.2
 */


require_once __DIR__ . "/../incl/configProcessing.inc.php";
require_once __DIR__ . "/../incl/api.inc.php";
require_once __DIR__ . "/../incl/db.inc.php";
require_once __DIR__ . "/../incl/processing.inc.php";
require_once __DIR__ . "/../incl/websocket/client_internal.php";
require_once __DIR__ . "/../incl/webui.inc.php";
require_once __DIR__ . "/../incl/config.inc.php";

$CONFIG->checkIfAuthenticated(true, true);


//Save settings
if (isset($_POST["isSaved"])) {
    saveSettings();
    //is done with AJAX call, therefore only "OK" is sent
    echo "OK";
    die();
}
if (isset($_POST["test_openai_lookup"])) {
    testOpenAiLookup();
    die();
}


$webUi = new WebUiGenerator(MENU_SETTINGS);
$webUi->addHeader();
$webUi->addCard("General Settings", getHtmlSettingsGeneral());
$webUi->addCard("Barcode Lookup Providers", getHtmlSettingsBarcodeLookup());
$webUi->addCard("Grocy API", getHtmlSettingsGrocyApi());
$webUi->addCard("Redis Cache", getHtmlSettingsRedis());
$webUi->addCard("Websocket Server Status", getHtmlSettingsWebsockets());
$webUi->addFooter();
$webUi->printHtml();


/**
 * Called when settings were saved. For each input, the setting
 * is saved as a database entry
 *
 * @return void
 */
function saveSettings(): void {
    $db     = DatabaseConnection::getInstance();
    $config = BBConfig::getInstance();
    foreach ($config as $key => $value) {
        if (isset($_POST[$key])) {
            if ($_POST[$key] != $value) {
                $value = sanitizeString($_POST[$key]);
                if (stringStartsWith($key, "BARCODE_")) {
                    $db->updateConfig($key, strtoupper($value));
                } else {
                    $db->updateConfig($key, $value);
                }
            }
        } else {
            if (isset($_POST[$key . "_hidden"]) && $_POST[$key . "_hidden"] != $value) {
                $db->updateConfig($key, sanitizeString($_POST[$key . "_hidden"]));
            }
        }
    }
}


/**
 * @return string
 */
function getHtmlSettingsGeneral(): string {
    $config = BBConfig::getInstance();
    $html   = new UiEditor(true, null, "settings1");
    $html->addHtml('<div class="flex-settings">');
    $html->addDiv($html->buildEditField("BARCODE_C", "Barcode: Consume", $config["BARCODE_C"])->generate(true), null, "flex-settings-child");
    $html->addDiv($html->buildEditField("BARCODE_CS", "Barcode: Consume (spoiled)", $config["BARCODE_CS"])->generate(true), null, "flex-settings-child");
    $html->addDiv($html->buildEditField("BARCODE_CA", "Barcode: Consume all", $config["BARCODE_CA"])->generate(true), null, "flex-settings-child");
    $html->addDiv($html->buildEditField("BARCODE_P", "Barcode: Purchase", $config["BARCODE_P"])->generate(true), null, "flex-settings-child");
    $html->addDiv($html->buildEditField("BARCODE_O", "Barcode: Open", $config["BARCODE_O"])->generate(true), null, "flex-settings-child");
    $html->addDiv($html->buildEditField("BARCODE_GS", "Barcode: Inventory", $config["BARCODE_GS"])->generate(true), null, "flex-settings-child");
    $html->addDiv($html->buildEditField("BARCODE_Q", "Barcode: Quantity", $config["BARCODE_Q"])->generate(true), null, "flex-settings-child");
    $html->addDiv($html->buildEditField("BARCODE_AS", "Barcode: Add to shopping list", $config["BARCODE_AS"])->generate(true), null, "flex-settings-child");
    $html->addDiv($html->buildEditField("REVERT_TIME", "Revert state to &quot;Consume&quot; after time passed in minutes", $config["REVERT_TIME"])
        ->pattern('-?[0-9]*(\.[0-9]+)?')
        ->onKeyPress('return (event.charCode == 8 || event.charCode == 0) ? null : event.charCode >= 48 && event.charCode <= 57')
        ->generate(true)
        , null, "flex-settings-child");
    $html->addHtml('</div>');
    $html->addLineBreak();

    $html->addCheckbox("REVERT_SINGLE", "Revert after single item scan in &quot;Open&quot; or &quot;Spoiled&quot; mode", $config["REVERT_SINGLE"], false, false);
    $html->addCheckbox("SHOPPINGLIST_REMOVE", "Remove purchased items from shoppinglist", $config["SHOPPINGLIST_REMOVE"], false, false);
    $html->addCheckbox("CONSUME_SAVED_QUANTITY", "Consume amount of quantity saved for barcode", $config["CONSUME_SAVED_QUANTITY"], false, false);
    $html->addCheckbox("USE_GROCY_QU_FACTOR", "Use Grocys quantity conversion", $config["USE_GROCY_QU_FACTOR"], false, false);
    $html->addCheckbox("WS_FULLSCREEN", "Show Screen module in fullscreen", $config["WS_FULLSCREEN"], false, false);
    $html->addCheckbox("USE_GENERIC_NAME", "Use generic names for lookup", $config["USE_GENERIC_NAME"], false, false);
    $html->addCheckbox("SHOW_STOCK_ON_SCAN", "Show stock amount on scan", $config["SHOW_STOCK_ON_SCAN"], false, false);
    $html->addCheckbox("SAVE_BARCODE_NAME", "Save name from lookup to barcode", $config["SAVE_BARCODE_NAME"], false, false);
    $html->addCheckbox("MORE_VERBOSE", "More verbose logs", $config["MORE_VERBOSE"], false, false);
    $html->addLineBreak(2);
    $html->addHtml('<small><i>Hint: You can find picture files of the default barcodes in the &quot;example&quot; folder or <a style="color: inherit;" href="https://github.com/Forceu/barcodebuddy/tree/master/example/defaultBarcodes">online</a></i></small>');
    $html->addHiddenField("isSaved", "1");

    return $html->getHtml();
}


/**
 * @return string
 */
function getHtmlSettingsGrocyApi(): string {
    $config = BBConfig::getInstance();
    $html   = new UiEditor(true, null, "settings2");
    $html->buildEditField('GROCY_API_URL', 'Grocy API URL', $config["GROCY_API_URL"])
        ->pattern('https://.*/api/|http://.*/api/|https://.*/api|http://.*/api')
        ->setPlaceholder('e.g. https://your.grocy.com/api/')
        ->generate();
    $html->buildEditField('GROCY_API_KEY', 'Grocy API Key', $config["GROCY_API_KEY"])
        ->pattern('[A-Za-z0-9]{50}')
        ->generate();
    $html->addLineBreak(2);
    $html->addHtml(checkGrocyConnection());
    return $html->getHtml();
}

/**
 * @return string
 */
function getHtmlSettingsBarcodeLookup(): string {
    $config = BBConfig::getInstance();
    $html   = new UiEditor(true, null, "settings3");
    $html->addScriptFile("../incl/js/Sortable.min.js");
    $html->addHtml("Use Drag&amp;Drop for changing lookup order");
    $html->addHtml('<ul class="demo-list-item mdl-list" id="providers">');

    $providerList = getProviderListItems($html);
    $orderAsArray = explode(",", $config["LOOKUP_ORDER"]);
    foreach ($orderAsArray as $orderId) {
        $html->addHtml($providerList["id" . $orderId]);
    }


    $html->addHtml('</ul>');
    $html->addLineBreak();

    $openAiOptionsDisplay = ($config["LOOKUP_USE_OPENAI"] == "1") ? "block" : "none";
    $html->addHtml('<div id="openaiProviderOptions" style="display:' . $openAiOptionsDisplay . '; border:1px solid #ddd; padding:12px; margin-bottom:12px;">');
    $html->addHtml("<b>OpenAI Lookup Settings</b><br><small>Only used when the OpenAI provider is enabled above.</small>");
    $html->addLineBreak();
    $html->addHtml('<small><b>Hint:</b> You need an OpenAI developer account with API access and available credit/balance. Each lookup sends a request, consumes tokens and therefore costs money.</small>');
    $html->addLineBreak();
    $html->addLineBreak();
    $html->addHtml((new EditFieldBuilder(
        'LOOKUP_OPENAI_API_KEY',
        'OpenAI API Key (ChatGPT Lookup)',
        $config["LOOKUP_OPENAI_API_KEY"],
        $html))
        ->required($config["LOOKUP_USE_OPENAI"])
        ->pattern('.{20,}')
        ->type('password')
        ->disabled(!$config["LOOKUP_USE_OPENAI"])
        ->generate(true)
    );
    $html->addLineBreak();
    $openAiModelOptions = array(
        "gpt-5.2" => "gpt-5.2",
        "gpt-5.2-pro" => "gpt-5.2-pro",
        "gpt-5" => "gpt-5",
        "gpt-5-mini" => "gpt-5-mini",
        "gpt-5-nano" => "gpt-5-nano",
        "gpt-4.1" => "gpt-4.1",
        "gpt-4.1-mini" => "gpt-4.1-mini",
        "gpt-4o" => "gpt-4o",
        "gpt-4o-mini" => "gpt-4o-mini"
    );
    $selectedModel = $config["LOOKUP_OPENAI_MODEL"] ?? "gpt-4.1-mini";
    $selectDisabled = ($config["LOOKUP_USE_OPENAI"] == "1") ? "" : " disabled";
    $selectHtml = '<label for="LOOKUP_OPENAI_MODEL"><b>OpenAI Model</b></label><br>';
    $selectHtml .= '<select id="LOOKUP_OPENAI_MODEL" name="LOOKUP_OPENAI_MODEL" style="width:100%;max-width:420px;padding:6px;"' . $selectDisabled . '>';
    foreach ($openAiModelOptions as $value => $label) {
        $selectedHtml = ($selectedModel === $value) ? ' selected' : '';
        $selectHtml .= '<option value="' . sanitizeString($value) . '"' . $selectedHtml . '>' . sanitizeString($label) . '</option>';
    }
    $selectHtml .= '</select>';
    $html->addHtml($selectHtml);
    $html->addLineBreak();

    $html->addLineBreak();
    $html->addHtml("<b>OpenAI naming schema</b><br><small>Choose the exact components that must be returned. If one selected component cannot be determined, the lookup returns UNKNOWN.</small>");
    $html->addLineBreak();
    $html->addCheckbox("LOOKUP_OPENAI_NAME_MANUFACTURER", "Brand / trade name", $config["LOOKUP_OPENAI_NAME_MANUFACTURER"], !$config["LOOKUP_USE_OPENAI"], false);
    $html->addCheckbox("LOOKUP_OPENAI_NAME_PRODUCT", "Product name", $config["LOOKUP_OPENAI_NAME_PRODUCT"], !$config["LOOKUP_USE_OPENAI"], false);
    $html->addCheckbox("LOOKUP_OPENAI_NAME_PACKSIZE", "Package size", $config["LOOKUP_OPENAI_NAME_PACKSIZE"], !$config["LOOKUP_USE_OPENAI"], false);
    $html->addLineBreak();
    $html->addHtml((new EditFieldBuilder(
        'LOOKUP_OPENAI_TEST_BARCODE_TEMP',
        'Test Barcode',
        '4306188348191',
        $html))
        ->pattern('[0-9]{8,18}')
        ->disabled(!$config["LOOKUP_USE_OPENAI"])
        ->generate(true)
    );
    $html->addLineBreak();
    $testButtonHtml = $html->buildButton("testOpenAiLookupBtn", "Test OpenAI Lookup")
        ->setId("testOpenAiLookupBtn")
        ->setOnClick("return testOpenAiLookupRequest();")
        ->setRaised(true)
        ->setIsAccent(true)
        ->setDisabled(!$config["LOOKUP_USE_OPENAI"])
        ->generate(true);
    $html->addHtml('<div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">'
        . '<div style="flex:0 0 auto;">' . $testButtonHtml . '</div>'
        . '</div>');
    $html->addLineBreak();
    $html->addHtml('<div id="openaiLookupTestStatus" style="display:none; margin:6px 0 8px 0; font-weight:600;"></div>');
    $html->addHtml('<div id="openaiLookupTestResult" style="display:block; white-space:pre-wrap; font-family:monospace; background:#f3f4f6; border:1px solid #d6d8dc; border-radius:4px; padding:10px; margin-top:6px; min-height:44px;"></div>');
    $html->addHtml('</div>');

    $html->addLineBreak();
    $html->addHtml((new EditFieldBuilder(
        'LOOKUP_UPC_DATABASE_KEY',
        'UPCDatabase.org API Key',
        $config["LOOKUP_UPC_DATABASE_KEY"],
        $html))
        ->required($config["LOOKUP_USE_UPC_DATABASE"])
        ->pattern('[A-Za-z0-9]{32}')
        ->disabled(!$config["LOOKUP_USE_UPC_DATABASE"])
        ->generate(true)
    );
    $html->addLineBreak();
    $html->addHtml((new EditFieldBuilder(
        'LOOKUP_OPENGTIN_KEY',
        'OpenGtinDb.org API Key',
        $config["LOOKUP_OPENGTIN_KEY"],
        $html))
        ->required($config["LOOKUP_USE_OPEN_GTIN_DATABASE"])
        ->pattern('[^%]{3,}')
        ->disabled(!$config["LOOKUP_USE_OPEN_GTIN_DATABASE"])
        ->generate(true)
    );

    $html->addLineBreak();
    $html->addHtml((new EditFieldBuilder(
        'LOOKUP_DISCOGS_TOKEN',
        'discogs.com Access Token',
        $config["LOOKUP_DISCOGS_TOKEN"],
        $html))
        ->required($config["LOOKUP_USE_DISCOGS"])
        ->pattern('[A-Za-z0-9]{40}')
        ->disabled(!$config["LOOKUP_USE_DISCOGS"])
        ->generate(true)
    );

    $html->addHiddenField("LOOKUP_ORDER", $config["LOOKUP_ORDER"]);

    $html->addScript("var elements = document.getElementById('providers');
                           var sortable = Sortable.create(elements, { animation: 150,
                                    dataIdAttr: 'data-id',
                                    onSort: function (evt) {
                                       document.getElementById('LOOKUP_ORDER').value = sortable.toArray().join();
                                    },});

                           function setOpenAiOptionsEnabled(isEnabled) {
                               var box = document.getElementById('openaiProviderOptions');
                               if (box) {
                                   box.style.display = isEnabled ? 'block' : 'none';
                               }
                               var ids = [
                                   'LOOKUP_OPENAI_MODEL',
                                   'LOOKUP_OPENAI_NAME_MANUFACTURER',
                                   'LOOKUP_OPENAI_NAME_PRODUCT',
                                   'LOOKUP_OPENAI_NAME_PACKSIZE',
                                   'testOpenAiLookupBtn'
                               ];
                               for (var i = 0; i < ids.length; i++) {
                                   var el = document.getElementById(ids[i]);
                                   if (el) {
                                       el.disabled = !isEnabled;
                                   }
                               }
                               if (!isEnabled) {
                                   var statusEl = document.getElementById('openaiLookupTestStatus');
                                   if (statusEl) {
                                       statusEl.style.display = 'none';
                                       statusEl.textContent = '';
                                   }
                                   var resultEl = document.getElementById('openaiLookupTestResult');
                                   if (resultEl) {
                                       resultEl.style.display = 'block';
                                       resultEl.textContent = '';
                                   }
                               }
                           }

                           function testOpenAiLookupRequest() {
                               var statusEl = document.getElementById('openaiLookupTestStatus');
                               var resultEl = document.getElementById('openaiLookupTestResult');
                               if (statusEl) {
                                   statusEl.style.display = 'none';
                                   statusEl.textContent = '';
                               }
                               if (resultEl) {
                                   resultEl.style.display = 'block';
                                   resultEl.textContent = 'Testing OpenAI lookup...';
                               }
                               var formEl = document.getElementById('settings3_form');
                               if (!formEl) {
                                   if (statusEl) {
                                       statusEl.style.color = '#b00020';
                                       statusEl.textContent = 'Test failed';
                                   }
                                   if (resultEl) {
                                       resultEl.textContent = 'Test failed: settings form not found';
                                   }
                                   return false;
                               }
                               var formData = new FormData(formEl);
                               formData.append('test_openai_lookup', '1');

                               var xhr = new XMLHttpRequest();
                               xhr.open('POST', window.location.href, true);
                               xhr.timeout = 30000;
                               xhr.onload = function() {
                                   if (!resultEl) {
                                       return;
                                   }
                                   if (xhr.status !== 200) {
                                       resultEl.textContent = 'Test failed (HTTP ' + xhr.status + ')';
                                       return;
                                   }
                                   try {
                                       var response = JSON.parse(xhr.responseText);
                                       if (response.ok) {
                                           if (statusEl) {
                                               statusEl.style.display = 'block';
                                               statusEl.style.color = '#137333';
                                               statusEl.textContent = 'OpenAI lookup successful';
                                           }
                                           var output = '';
                                           output += 'Barcode: ' + response.barcode + '\\n';
                                           output += 'Model: ' + (response.model || '-') + '\\n';
                                           if (response.parsed_fields) {
                                               output += '\\nParsed fields from AI response:\\n' + JSON.stringify(response.parsed_fields, null, 2) + '\\n';
                                           }
                                           output += '\\nRaw AI response:\\n' + (response.raw || '(empty)') + '\\n';
                                           output += '\\nFinal Result (server-composed):\\n' + response.name;
                                           resultEl.textContent = output;
                                       } else {
                                           if (statusEl) {
                                               statusEl.style.display = 'block';
                                               statusEl.style.color = '#b00020';
                                               statusEl.textContent = 'OpenAI lookup failed';
                                           }
                                           var errorOutput = '';
                                           errorOutput += 'Barcode: ' + response.barcode + '\\n';
                                           errorOutput += 'Model: ' + (response.model || '-') + '\\n';
                                           errorOutput += 'Error: ' + (response.error || 'Unknown error') + '\\n';
                                           if (response.parsed_fields) {
                                               errorOutput += '\\nParsed fields from AI response:\\n' + JSON.stringify(response.parsed_fields, null, 2) + '\\n';
                                           }
                                           errorOutput += '\\nRaw AI response:\\n' + (response.raw || '(empty)');
                                           resultEl.textContent = errorOutput;
                                       }
                                   } catch (e) {
                                       if (statusEl) {
                                           statusEl.style.display = 'block';
                                           statusEl.style.color = '#b00020';
                                           statusEl.textContent = 'OpenAI lookup failed';
                                       }
                                       resultEl.textContent = 'Invalid response from server:\\n' + xhr.responseText;
                                   }
                               };
                               xhr.onerror = function() {
                                   if (statusEl) {
                                       statusEl.style.display = 'block';
                                       statusEl.style.color = '#b00020';
                                       statusEl.textContent = 'OpenAI lookup failed';
                                   }
                                   if (resultEl) {
                                       resultEl.textContent = 'Test failed: network/XHR error while calling settings endpoint';
                                   }
                               };
                               xhr.onabort = function() {
                                   if (statusEl) {
                                       statusEl.style.display = 'block';
                                       statusEl.style.color = '#b00020';
                                       statusEl.textContent = 'OpenAI lookup aborted';
                                   }
                                   if (resultEl) {
                                       resultEl.textContent = 'Test aborted';
                                   }
                               };
                               xhr.ontimeout = function() {
                                   if (statusEl) {
                                       statusEl.style.display = 'block';
                                       statusEl.style.color = '#b00020';
                                       statusEl.textContent = 'OpenAI lookup timed out';
                                   }
                                   if (resultEl) {
                                       resultEl.textContent = 'Test timed out after 30s (request reached server but no response in time)';
                                   }
                               };
                               xhr.send(formData);
                               return false;
                           }

                           setOpenAiOptionsEnabled(document.getElementById('LOOKUP_USE_OPENAI') && document.getElementById('LOOKUP_USE_OPENAI').checked);");

    return $html->getHtml();
}

function testOpenAiLookup(): void {
    header('Content-Type: application/json');
    $barcode = "4306188348191";
    if (isset($_POST["LOOKUP_OPENAI_TEST_BARCODE_TEMP"])) {
        $postedBarcode = preg_replace('/[^0-9]/', '', strval($_POST["LOOKUP_OPENAI_TEST_BARCODE_TEMP"]));
        if ($postedBarcode !== "") {
            $barcode = $postedBarcode;
        }
    }
    $config  = BBConfig::getInstance();

    // Allow testing with current form values (even before saving)
    $config["LOOKUP_USE_OPENAI"] = "1";
    if (isset($_POST["LOOKUP_OPENAI_API_KEY"])) {
        $config["LOOKUP_OPENAI_API_KEY"] = sanitizeString($_POST["LOOKUP_OPENAI_API_KEY"]);
    }
    if (isset($_POST["LOOKUP_OPENAI_MODEL"]) && $_POST["LOOKUP_OPENAI_MODEL"] !== "") {
        $config["LOOKUP_OPENAI_MODEL"] = sanitizeString($_POST["LOOKUP_OPENAI_MODEL"]);
    }
    applyPostedCheckboxForTest($config, "LOOKUP_OPENAI_NAME_MANUFACTURER");
    applyPostedCheckboxForTest($config, "LOOKUP_OPENAI_NAME_PRODUCT");
    applyPostedCheckboxForTest($config, "LOOKUP_OPENAI_NAME_PACKSIZE");

    $provider = new ProviderOpenAI();
    $result   = $provider->lookupBarcode($barcode);

    if ($result != null && isset($result["name"])) {
        echo json_encode(array(
            "ok" => true,
            "barcode" => $barcode,
            "model" => $config["LOOKUP_OPENAI_MODEL"],
            "name" => html_entity_decode($result["name"], ENT_QUOTES, 'UTF-8'),
            "raw" => $provider->getLastResponseText(),
            "parsed_fields" => parseOpenAiRawJsonForTest($provider->getLastResponseText())
        ));
        return;
    }

    echo json_encode(array(
        "ok" => false,
        "barcode" => $barcode,
        "model" => $config["LOOKUP_OPENAI_MODEL"],
        "error" => $provider->getLastErrorMessage() ?? "No result",
        "raw" => $provider->getLastResponseText(),
        "parsed_fields" => parseOpenAiRawJsonForTest($provider->getLastResponseText())
    ));
}

function applyPostedCheckboxForTest(BBConfig $config, string $key): void {
    if (isset($_POST[$key])) {
        $config[$key] = "1";
        return;
    }
    if (isset($_POST[$key . "_hidden"])) {
        $config[$key] = sanitizeString($_POST[$key . "_hidden"]);
    }
}

function parseOpenAiRawJsonForTest(?string $raw): ?array {
    if ($raw == null) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return array(
            "brand" => $decoded["brand"] ?? null,
            "product_name" => $decoded["product_name"] ?? null,
            "package_size" => $decoded["package_size"] ?? null
        );
    }

    if (preg_match('/\{.*\}/s', $raw, $matches) !== 1) {
        return null;
    }
    $decoded = json_decode($matches[0], true);
    if (!is_array($decoded)) {
        return null;
    }
    return array(
        "brand" => $decoded["brand"] ?? null,
        "product_name" => $decoded["product_name"] ?? null,
        "package_size" => $decoded["package_size"] ?? null
    );
}

function generateApiKeyChangeScript(string $functionName, string $keyId): string {
    return "function " . $functionName . "(element) {
                apiEditField = document.getElementById('" . $keyId . "');
                if (!apiEditField) {
                    console.warn('Unable to find element " . $keyId . "');
                } else {
                    apiEditField.required = element.checked;
                    if (element.checked) {
                        apiEditField.parentNode.MaterialTextfield.enable();
                    } else {
                        apiEditField.parentNode.MaterialTextfield.disable();
                    }
                }
            }";
}

function getProviderListItems(UiEditor $html): array {
    $config                                 = BBConfig::getInstance();
    $result                                 = array();
    $result["id" . LOOKUP_ID_OPENFOODFACTS] = $html->addListItem($html->addCheckbox('LOOKUP_USE_OFF', 'Open Food Facts', $config["LOOKUP_USE_OFF"], false, false, true), "Uses OpenFoodFacts.org", LOOKUP_ID_OPENFOODFACTS, true);
    $result["id" . LOOKUP_ID_UPCDB]         = $html->addListItem($html->addCheckbox('LOOKUP_USE_UPC', 'UPC Item DB', $config["LOOKUP_USE_UPC"], false, false, true), "Uses UPCitemDB.com", LOOKUP_ID_UPCDB, true);
    $result["id" . LOOKUP_ID_ALBERTHEIJN]   = $html->addListItem($html->addCheckbox('LOOKUP_USE_AH', 'Albert Heijn', $config["LOOKUP_USE_AH"], false, false, true), "Uses AH.nl", LOOKUP_ID_ALBERTHEIJN, true);
    $result["id" . LOOKUP_ID_PLUS]          = $html->addListItem($html->addCheckbox('LOOKUP_USE_PLUS', 'Plus Supermarkt', $config["LOOKUP_USE_PLUS"], false, false, true), "Uses PLUS.nl", LOOKUP_ID_PLUS, true);
    $result["id" . LOOKUP_ID_JUMBO]         = $html->addListItem($html->addCheckbox('LOOKUP_USE_JUMBO', 'Jumbo', $config["LOOKUP_USE_JUMBO"], false, false, true), "Uses Jumbo.com (slow)", LOOKUP_ID_JUMBO, true);
    $result["id" . LOOKUP_ID_UPCDATABASE]   = $html->addListItem((new CheckBoxBuilder(
        "LOOKUP_USE_UPC_DATABASE",
        "UPC Database",
        $config["LOOKUP_USE_UPC_DATABASE"],
        $html)
    )->onCheckChanged(
        "handleUPCDBChange(this)",
        generateApiKeyChangeScript("handleUPCDBChange", "LOOKUP_UPC_DATABASE_KEY"))
        ->generate(true), "Uses UPCDatabase.org", LOOKUP_ID_UPCDATABASE, true);

    $result["id" . LOOKUP_ID_OPENGTINDB] = $html->addListItem((new CheckBoxBuilder(
        "LOOKUP_USE_OPEN_GTIN_DATABASE",
        "Open EAN / GTIN Database",
        $config["LOOKUP_USE_OPEN_GTIN_DATABASE"],
        $html)
    )->onCheckChanged(
        "handleOpenGtinChange(this)",
        generateApiKeyChangeScript("handleOpenGtinChange", "LOOKUP_OPENGTIN_KEY"))
        ->generate(true), "Uses OpenGtinDb.org", LOOKUP_ID_OPENGTINDB, true);

    $result["id" . LOOKUP_ID_DISCOGS]   = $html->addListItem((new CheckBoxBuilder(
        "LOOKUP_USE_DISCOGS",
        "Discogs Database",
        $config["LOOKUP_USE_DISCOGS"],
        $html)
    )->onCheckChanged(
        "handleDiscogsChange(this)",
        generateApiKeyChangeScript("handleDiscogsChange", "LOOKUP_DISCOGS_TOKEN"))
        ->generate(true), "Uses Discogs.com", LOOKUP_ID_DISCOGS, true);

    $result["id" . LOOKUP_ID_OPENAI] = $html->addListItem((new CheckBoxBuilder(
        "LOOKUP_USE_OPENAI",
        "OpenAI (ChatGPT)",
        $config["LOOKUP_USE_OPENAI"],
        $html)
    )->onCheckChanged(
        "handleOpenAIChange(this)",
        "function handleOpenAIChange(element) {
                apiEditField = document.getElementById('LOOKUP_OPENAI_API_KEY');
                if (!apiEditField) {
                    console.warn('Unable to find element LOOKUP_OPENAI_API_KEY');
                } else {
                    apiEditField.required = element.checked;
                    if (element.checked) {
                        apiEditField.parentNode.MaterialTextfield.enable();
                    } else {
                        apiEditField.parentNode.MaterialTextfield.disable();
                    }
                }
                if (typeof setOpenAiOptionsEnabled === 'function') {
                    setOpenAiOptionsEnabled(element.checked);
                }
            }")
        ->generate(true), "Uses OpenAI ChatGPT API for barcode name lookup", LOOKUP_ID_OPENAI, true);

    $bbServerSubtitle                    = "Uses " . BarcodeFederation::HOST_READABLE;
    if (!$config["BBUDDY_SERVER_ENABLED"])
        $bbServerSubtitle = "Enable Federation for this feature";
    $result["id" . LOOKUP_ID_FEDERATION] = $html->addListItem($html->addCheckbox('LOOKUP_USE_BBUDDY_SERVER', 'Barcode Buddy Federation', $config["LOOKUP_USE_BBUDDY_SERVER"], !$config["BBUDDY_SERVER_ENABLED"], false, true), $bbServerSubtitle, LOOKUP_ID_FEDERATION, true);
    return $result;
}


/**
 * @return string
 */
function checkGrocyConnection(): string {
    $config = BBConfig::getInstance();
    $result = API::checkApiConnection($config["GROCY_API_URL"], $config["GROCY_API_KEY"]);
    if ($result === true) {
        return '<span style="color:green">Successfully connected to Grocy, valid API key.</span>';
    } else {
        return '<span style="color:red">Unable to connect to Grocy! ' . $result . '</span>';
    }
}

function checkRedisConnection(UiEditor &$html): void {
    $error = null;
    try {
        $connected = RedisConnection::ping();
    } catch (Exception $error) {
        $error     = $error->getMessage();
        $connected = false;
    }
    if (!$connected) {
        if ($error == null)
            $error = RedisConnection::getErrorMessage();
        $html->addHtml('<span style="color:red">Cannot connect to Rediscache! ' . $error . '</span>');
    } else {
        $html->addHtml('<span style="color:green">Redis cache is available.</span>');
        $html->addSpaces(4);
        $html->addButton("updatecache", "Update Cache", "updateRedisCacheAndFederation(true)");
    }
}


/**
 * @return string
 */
function getHtmlSettingsWebsockets(): string {
    global $CONFIG;
    $client = new SocketClient('127.0.0.1', $CONFIG->PORT_WEBSOCKET_SERVER);
    if ($client->connect() !== false) {
        return '<span style="color:green">Websocket server is running.</span>';
    } else {
        return '<span style="color:red">Websocket server is not running! ' . $client->getLastError() . '</span>';
    }
}

/**
 * @return string
 */
function getHtmlSettingsRedis(): string {
    $config = BBConfig::getInstance();
    $html   = new UiEditor(true, null, "settings4");
    $html->addCheckbox("USE_REDIS", "Use Redis cache", $config["USE_REDIS"], false, false);
    $html->addLineBreak(1);
    $html->buildEditField('REDIS_IP', 'Redis Server IP', $config["REDIS_IP"])
        ->setPlaceholder('e.g. 127.0.0.1')
        ->generate();
    $html->buildEditField('REDIS_PORT', 'Redis Server Port', $config["REDIS_PORT"])
        ->setPlaceholder('e.g. 6379')
        ->pattern('^([0-9]{1,4}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])$')
        ->generate();
    $html->addLineBreak();
    $html->buildEditField('REDIS_PW', 'Redis Password', $config["REDIS_PW"])
        ->setPlaceholder('leave blank if none set')
        ->required(false)
        ->type("password")
        ->generate();
    if ($config["USE_REDIS"]) {
        $html->addLineBreak(2);
        checkRedisConnection($html);
    }
    return $html->getHtml();
}
