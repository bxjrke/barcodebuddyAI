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
if (isset($_POST["sync_grocy_extended_meta"])) {
    syncGrocyExtendedMeta();
    die();
}
if (isset($_POST["extended_create_dry_run"])) {
    runExtendedCreateDryRun();
    die();
}


$webUi = new WebUiGenerator(MENU_SETTINGS);
$webUi->addHeader();
$webUi->addCard("General Settings", getHtmlSettingsGeneral());
$webUi->addCard("Barcode Lookup Providers", getHtmlSettingsBarcodeLookup());
$webUi->addCard("Extended Create Mode (Grocy)", getHtmlSettingsExtendedCreateMode());
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
function getHtmlSettingsExtendedCreateMode(): string {
    $config = BBConfig::getInstance();
    $html   = new UiEditor(true, null, "settingsExtendedCreate");

    $categoriesRaw = json_decode($config["EXT_CREATE_GROCY_CATEGORIES"] ?? "[]", true);
    $locationsRaw = json_decode($config["EXT_CREATE_GROCY_LOCATIONS"] ?? "[]", true);
    $unitsRaw = json_decode($config["EXT_CREATE_GROCY_UNITS"] ?? "[]", true);

    $categories = extractMetaNamesForMatching($categoriesRaw);
    $locations = extractMetaNamesForMatching($locationsRaw);
    $units = extractMetaNamesForMatching($unitsRaw);

    $defaultBarcode = $config["EXT_CREATE_DRYRUN_DEFAULT_BARCODE"] ?? "4306188348191";
    $defaultName = $config["EXT_CREATE_DRYRUN_DEFAULT_NAME"] ?? "Jeden Tag Schokoladen Schaumküsse 300g";

    $lastSync = $config["EXT_CREATE_GROCY_META_LAST_SYNC"];
    if ($lastSync == null || $lastSync == "0") {
        $lastSync = "never";
    }

    $enabledChecked = ($config["EXT_CREATE_MODE_ENABLED"] == "1") ? " checked" : "";
    $html->addHtml('<label class="mdl-switch mdl-js-switch mdl-js-ripple-effect" for="EXT_CREATE_MODE_ENABLED">'
        . '<input type="checkbox" id="EXT_CREATE_MODE_ENABLED" name="EXT_CREATE_MODE_ENABLED" value="1" class="mdl-switch__input"' . $enabledChecked . '>'
        . '<span class="mdl-switch__label">Enable Extended Create Mode</span>'
        . '</label><input type="hidden" value="0" name="EXT_CREATE_MODE_ENABLED_hidden"/>');

    $html->addCheckbox("EXT_CREATE_DRY_RUN", "Dry run mode (preview only, no create)", $config["EXT_CREATE_DRY_RUN"], false, false);
    $html->addHiddenField("EXT_CREATE_AUTO_ASSIGN_LOCATION_AI", "0");

    $detailsOpen = ($config["EXT_CREATE_MODE_ENABLED"] == "1") ? " open" : "";
    $html->addHtml('<details id="extendedCreateSettingsBody"' . $detailsOpen . ' style="margin-top:10px;">');
    $html->addHtml('<summary style="cursor:pointer; font-weight:600;">Extended Create settings</summary>');

    $html->addHtml('<div style="border:1px solid #ddd; border-radius:6px; padding:12px; margin:12px 0;">');
    $html->addHtml('<b>Grocy metadata</b><br><small>Categories: ' . count($categories) . ' | Locations: ' . count($locations) . ' | Units: ' . count($units) . '</small><br>');
    $html->addHtml('<small>Last sync: <span id="grocyMetaLastSync">' . sanitizeString($lastSync) . '</span></small><br>');
    $html->addHtml('<button type="button" id="syncGrocyMetaBtn" class="mdl-button mdl-js-button mdl-button--raised mdl-button--accent" onclick="return syncGrocyExtendedMeta();">Sync now</button>');
    $html->addHtml('<div id="grocyMetaSyncStatus" style="display:none; margin-top:8px; font-weight:600;"></div>');

    $existingMap = parseCategoryLocationMapping($config["EXT_CREATE_CATEGORY_LOCATION_MAP"] ?? "");
    $html->addLineBreak();
    $html->addHtml('<b>Category -> default location mapping</b><br><small>Choose one default location per category.</small>');
    $html->addHtml('<input type="hidden" id="EXT_CREATE_CATEGORY_LOCATION_MAP" name="EXT_CREATE_CATEGORY_LOCATION_MAP" value="' . sanitizeString($config["EXT_CREATE_CATEGORY_LOCATION_MAP"] ?? "") . '">');

    if (count($categories) > 0 && count($locations) > 0) {
        $tableHtml = '<table style="width:100%; border-collapse:collapse; margin-top:8px;">';
        $tableHtml .= '<tr><th style="text-align:left; border-bottom:1px solid #ddd; padding:6px;">Category</th><th style="text-align:left; border-bottom:1px solid #ddd; padding:6px;">Default location</th></tr>';
        foreach ($categories as $catName) {
            $mapped = $existingMap[strtolower($catName)] ?? "";
            $tableHtml .= '<tr>';
            $tableHtml .= '<td style="padding:6px; border-bottom:1px solid #f0f0f0;">' . sanitizeString($catName) . '</td>';
            $tableHtml .= '<td style="padding:6px; border-bottom:1px solid #f0f0f0;">';
            $tableHtml .= '<select class="ext-map-location" data-category="' . sanitizeString($catName) . '" style="width:100%; max-width:360px; padding:6px;">';
            $tableHtml .= '<option value="">(no override)</option>';
            foreach ($locations as $locName) {
                $selected = (strcasecmp($locName, $mapped) === 0) ? ' selected' : '';
                $tableHtml .= '<option value="' . sanitizeString($locName) . '"' . $selected . '>' . sanitizeString($locName) . '</option>';
            }
            $tableHtml .= '</select>';
            $tableHtml .= '</td></tr>';
        }
        $tableHtml .= '</table>';
        $html->addHtml($tableHtml);
    } else {
        $html->addHtml('<small>No metadata available yet. Click "Sync now" first.</small>');
    }

    $html->addHtml('</div>');

    $html->addHtml('<div style="border:1px solid #ddd; border-radius:6px; padding:12px; margin:12px 0;">');
    $html->addHtml('<b>Test</b><br><small>Preview how values would be resolved for a product.</small>');
    $html->addHtml((new EditFieldBuilder('EXT_CREATE_DRYRUN_BARCODE', 'Barcode', $defaultBarcode, $html))
        ->pattern('[0-9A-Za-z\-]{3,30}')
        ->generate(true)
    );
    $html->addHtml((new EditFieldBuilder('EXT_CREATE_DRYRUN_NAME', 'Product name', $defaultName, $html))
        ->pattern('.{2,120}')
        ->generate(true)
    );
    $html->addHtml('<button type="button" id="runExtendedCreateDryRunBtn" class="mdl-button mdl-js-button mdl-button--raised mdl-button--accent" onclick="return runExtendedCreateDryRun(false);">Test</button>');
    $html->addHtml('<div id="extendedCreateDryRunStatus" style="display:none; margin-top:8px; font-weight:600;"></div>');
    $html->addHtml('<pre id="extendedCreateDryRunResult" style="display:none; white-space:pre-wrap; margin-top:8px; background:#f3f4f6; border:1px solid #d6d8dc; border-radius:4px; padding:10px;"></pre>');
    $html->addHtml('</div>');

    $html->addHtml('</details>');

    $html->addLineBreak();
    $html->addHtml("<script>
        function serializeCategoryLocationMap() {
            var hidden = document.getElementById('EXT_CREATE_CATEGORY_LOCATION_MAP');
            if (!hidden) return;
            var rows = document.querySelectorAll('.ext-map-location');
            var parts = [];
            for (var i = 0; i < rows.length; i++) {
                var sel = rows[i];
                if (sel.value && sel.value.trim() !== '') {
                    var cat = (sel.getAttribute('data-category') || '').trim();
                    parts.push(cat + ' = ' + sel.value.trim());
                }
            }
            hidden.value = parts.join('\\n');
        }

        function updateExtendedCreateVisibility() {
            var panel = document.getElementById('extendedCreateSettingsBody');
            var toggle = document.getElementById('EXT_CREATE_MODE_ENABLED');
            if (!panel || !toggle) return;

            var checked = !!toggle.checked;
            if (!checked && toggle.parentNode && toggle.parentNode.classList && toggle.parentNode.classList.contains('is-checked')) {
                checked = true;
            }
            panel.open = checked;
        }

        document.addEventListener('change', function(ev) {
            if (ev.target && ev.target.classList && ev.target.classList.contains('ext-map-location')) {
                serializeCategoryLocationMap();
            }
            if (ev.target && ev.target.id === 'EXT_CREATE_MODE_ENABLED') {
                setTimeout(updateExtendedCreateVisibility, 0);
            }
        });

        document.addEventListener('click', function(ev) {
            if (ev.target && (ev.target.id === 'EXT_CREATE_MODE_ENABLED' || ev.target.getAttribute('for') === 'EXT_CREATE_MODE_ENABLED')) {
                setTimeout(updateExtendedCreateVisibility, 0);
            }
        });

        function syncGrocyExtendedMeta() {
            var btn = document.getElementById('syncGrocyMetaBtn');
            var status = document.getElementById('grocyMetaSyncStatus');
            if (btn) btn.disabled = true;
            if (status) {
                status.style.display = 'block';
                status.style.color = '#555';
                status.textContent = 'Syncing...';
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                if (btn) btn.disabled = false;
                if (!status) return;
                if (xhr.status !== 200) {
                    status.style.color = 'red';
                    status.textContent = 'Sync failed (HTTP ' + xhr.status + ')';
                    return;
                }
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.ok) {
                        status.style.color = 'green';
                        status.textContent = 'Synced: ' + data.categories + ' categories, ' + data.locations + ' locations, ' + data.units + ' units. Reload page to refresh mapping table.';
                        var lastSync = document.getElementById('grocyMetaLastSync');
                        if (lastSync && data.last_sync) lastSync.textContent = data.last_sync;
                    } else {
                        status.style.color = 'red';
                        status.textContent = data.error || 'Sync failed';
                    }
                } catch (e) {
                    status.style.color = 'red';
                    status.textContent = 'Sync failed (invalid response)';
                }
            };
            xhr.send('sync_grocy_extended_meta=1');
            return false;
        }

        function runExtendedCreateDryRun(doCreate) {
            serializeCategoryLocationMap();

            var btn = document.getElementById('runExtendedCreateDryRunBtn');
            var status = document.getElementById('extendedCreateDryRunStatus');
            var result = document.getElementById('extendedCreateDryRunResult');
            var barcode = document.getElementById('EXT_CREATE_DRYRUN_BARCODE');
            var name = document.getElementById('EXT_CREATE_DRYRUN_NAME');

            if (!barcode || !name || !barcode.value || !name.value) {
                if (status) {
                    status.style.display = 'block';
                    status.style.color = 'red';
                    status.textContent = 'Please provide barcode and product name';
                }
                return false;
            }

            if (btn) btn.disabled = true;
            if (status) {
                status.style.display = 'block';
                status.style.color = '#555';
                status.textContent = 'Calculating test...';
            }
            if (result) {
                result.style.display = 'block';
                result.textContent = '';
            }

            var params = 'extended_create_dry_run=1&barcode=' + encodeURIComponent(barcode.value) + '&name=' + encodeURIComponent(name.value) + '&create=' + (doCreate ? '1' : '0');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                if (btn) btn.disabled = false;
                if (xhr.status !== 200) {
                    if (status) {
                        status.style.color = 'red';
                        status.textContent = 'Request failed (HTTP ' + xhr.status + ')';
                    }
                    return;
                }
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (!data.ok) {
                        if (status) {
                            status.style.color = 'red';
                            status.textContent = data.error || 'Request failed';
                        }
                        return;
                    }
                    if (status) {
                        status.style.color = 'green';
                        status.textContent = 'Test calculated';
                    }
                    if (result) {
                        var r = data.resolved || {};
                        var cat = (r.category && r.category.value) ? r.category.value : '—';
                        var loc = (r.location && r.location.value) ? r.location.value : '—';
                        var unit = (r.unit && r.unit.value) ? r.unit.value : '—';
                        var mhd = (r.default_shelf_life_days && r.default_shelf_life_days.value !== null) ? r.default_shelf_life_days.value + ' days' : '—';
                        var sourceMap = { 'ai': 'AI suggestion', 'manual_mapping': 'Category mapping', 'fallback': 'Fallback', 'missing': 'Missing' };
                        var src = function(v){ return sourceMap[v] || v || '—'; };
                        var catSrc = (r.category && r.category.source) ? r.category.source : '—';
                        var locSrc = (r.location && r.location.source) ? r.location.source : '—';
                        var unitSrc = (r.unit && r.unit.source) ? r.unit.source : '—';
                        var mhdSrc = (r.default_shelf_life_days && r.default_shelf_life_days.source) ? r.default_shelf_life_days.source : '—';

                        result.textContent =
                            'Category: ' + cat + '  (' + src(catSrc) + ')\n'
                            + 'Location: ' + loc + '  (' + src(locSrc) + ')\n'
                            + 'Unit: ' + unit + '  (' + src(unitSrc) + ')\n'
                            + 'Shelf life: ' + mhd + '  (' + src(mhdSrc) + ')';
                    }
                } catch (e) {
                    if (status) {
                        status.style.color = 'red';
                        status.textContent = 'Request failed (invalid response)';
                    }
                }
            };
            xhr.send(params);
            return false;
        }

        // Initial + resilient updates (MDL checkbox state can change without reliable native change events)
        serializeCategoryLocationMap();
        setTimeout(updateExtendedCreateVisibility, 0);
        setTimeout(updateExtendedCreateVisibility, 200);
        setInterval(updateExtendedCreateVisibility, 400);
    </script>");

    return $html->getHtml();
}

function decodeMetaNames(?string $json): array {
    if ($json == null || trim($json) === "") {
        return array();
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return array();
    }
    $names = array();
    foreach ($decoded as $row) {
        if (is_array($row) && isset($row["name"])) {
            $names[] = html_entity_decode(strval($row["name"]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $names;
}

function syncGrocyExtendedMeta(): void {
    header('Content-Type: application/json');
    $db = DatabaseConnection::getInstance();

    try {
        $meta = API::getExtendedCreateMeta();
        $lastSync = date('Y-m-d H:i:s');

        $db->updateConfig("EXT_CREATE_GROCY_CATEGORIES", json_encode($meta["categories"]));
        $db->updateConfig("EXT_CREATE_GROCY_LOCATIONS", json_encode($meta["locations"]));
        $db->updateConfig("EXT_CREATE_GROCY_UNITS", json_encode($meta["units"]));
        $db->updateConfig("EXT_CREATE_GROCY_META_LAST_SYNC", $lastSync);

        echo json_encode(array(
            "ok" => true,
            "categories" => count($meta["categories"]),
            "locations" => count($meta["locations"]),
            "units" => count($meta["units"]),
            "last_sync" => $lastSync,
        ));
    } catch (Exception $e) {
        echo json_encode(array(
            "ok" => false,
            "error" => "Unable to sync Grocy metadata: " . $e->getMessage(),
        ));
    }
}

function runExtendedCreateDryRun(): void {
    header('Content-Type: application/json');
    $config = BBConfig::getInstance();

    $barcode = trim(strval($_POST["barcode"] ?? ""));
    $name = trim(strval($_POST["name"] ?? ""));
    $create = (($_POST["create"] ?? "0") === "1");

    if ($barcode === "" || $name === "") {
        echo json_encode(array("ok" => false, "error" => "Missing barcode or product name"));
        return;
    }

    $categoriesRaw = json_decode($config["EXT_CREATE_GROCY_CATEGORIES"] ?? "[]", true);
    $locationsRaw = json_decode($config["EXT_CREATE_GROCY_LOCATIONS"] ?? "[]", true);
    $unitsRaw = json_decode($config["EXT_CREATE_GROCY_UNITS"] ?? "[]", true);

    $categories = extractMetaNamesForMatching($categoriesRaw);
    $locations = extractMetaNamesForMatching($locationsRaw);
    $units = extractMetaNamesForMatching($unitsRaw);

    if (count($categories) === 0 || count($locations) === 0 || count($units) === 0) {
        echo json_encode(array("ok" => false, "error" => "Grocy metadata cache is empty. Please run sync first."));
        return;
    }

    $aiSuggestion = null;
    $aiError = null;
    if ($config["LOOKUP_USE_OPENAI"] == "1" && ($config["LOOKUP_OPENAI_API_KEY"] ?? "") != "") {
        require_once __DIR__ . "/../incl/lookupProviders/ProviderOpenAI.php";
        $provider = new ProviderOpenAI();
        $aiSuggestion = $provider->suggestExtendedCreateData($barcode, $name, $categories, $locations, $units);
        $aiError = $provider->getLastErrorMessage();
    }

    $mapping = parseCategoryLocationMapping($config["EXT_CREATE_CATEGORY_LOCATION_MAP"] ?? "");

    $resolvedCategory = resolveFromAllowedList($aiSuggestion["category"] ?? null, $categories);
    $resolvedUnit = resolveFromAllowedList($aiSuggestion["unit"] ?? null, $units);

    $resolvedLocation = null;
    $locationSource = "fallback";
    if ($resolvedCategory != null && isset($mapping[strtolower($resolvedCategory)])) {
        $mapped = resolveFromAllowedList($mapping[strtolower($resolvedCategory)], $locations);
        if ($mapped != null) {
            $resolvedLocation = $mapped;
            $locationSource = "manual_mapping";
        }
    }
    if ($resolvedLocation == null) {
        $resolvedLocation = resolveFromAllowedList($aiSuggestion["location"] ?? null, $locations);
        if ($resolvedLocation != null) {
            $locationSource = "ai";
        }
    }
    if ($resolvedLocation == null && count($locations) > 0) {
        $resolvedLocation = $locations[0];
        $locationSource = "fallback";
    }

    $shelfLife = null;
    if (isset($aiSuggestion["default_shelf_life_days"]) && is_numeric($aiSuggestion["default_shelf_life_days"])) {
        $shelfLife = intval($aiSuggestion["default_shelf_life_days"]);
    }

    $categoryId = resolveMetaIdByName($resolvedCategory, $categoriesRaw);
    $locationId = resolveMetaIdByName($resolvedLocation, $locationsRaw);
    $unitId = resolveMetaIdByName($resolvedUnit, $unitsRaw);

    $created = false;
    $createdProductId = null;
    $createError = null;

    if ($create) {
        if ($config["EXT_CREATE_DRY_RUN"] == "1") {
            $createError = "Dry run mode is enabled. Disable EXT_CREATE_DRY_RUN to create products.";
        } else if ($categoryId === null || $locationId === null || $unitId === null) {
            $createError = "Cannot create product. Missing resolved category/location/unit IDs.";
        } else {
            $payload = array(
                "name" => $name,
                "barcode" => $barcode,
                "product_group_id" => intval($categoryId),
                "location_id" => intval($locationId),
                "qu_id_purchase" => intval($unitId),
                "default_best_before_days" => ($shelfLife !== null ? intval($shelfLife) : -1),
            );

            $createdProductId = API::createExtendedProduct($payload);
            if ($createdProductId !== null) {
                $created = true;
            } else {
                $createError = "Grocy create request failed";
            }
        }
    }

    echo json_encode(array(
        "ok" => true,
        "created" => $created,
        "created_product_id" => $createdProductId,
        "create_error" => $createError,
        "input" => array("barcode" => $barcode, "name" => $name),
        "resolved" => array(
            "category" => array("value" => $resolvedCategory, "source" => ($resolvedCategory != null ? "ai" : "missing"), "id" => $categoryId),
            "location" => array("value" => $resolvedLocation, "source" => $locationSource, "id" => $locationId),
            "unit" => array("value" => $resolvedUnit, "source" => ($resolvedUnit != null ? "ai" : "missing"), "id" => $unitId),
            "default_shelf_life_days" => array("value" => $shelfLife, "source" => ($shelfLife !== null ? "ai" : "missing")),
        ),
        "ai_suggestion" => $aiSuggestion,
        "ai_error" => $aiError,
        "dry_run_enabled" => ($config["EXT_CREATE_DRY_RUN"] == "1"),
    ));
}

function extractMetaNamesForMatching($raw): array {
    if (!is_array($raw)) return array();
    $names = array();
    foreach ($raw as $row) {
        if (is_array($row) && isset($row["name"])) {
            $names[] = html_entity_decode(strval($row["name"]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $names;
}

function parseCategoryLocationMapping(string $mappingText): array {
    $map = array();
    $lines = preg_split('/\r\n|\r|\n/', $mappingText);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === "" || strpos($line, "=") === false) continue;
        $parts = explode("=", $line, 2);
        $cat = trim($parts[0]);
        $loc = trim($parts[1]);
        if ($cat !== "" && $loc !== "") {
            $map[strtolower($cat)] = $loc;
        }
    }
    return $map;
}

function resolveFromAllowedList(?string $value, array $allowed): ?string {
    if ($value == null || trim($value) === "") return null;
    foreach ($allowed as $item) {
        if (strcasecmp(trim($item), trim($value)) === 0) {
            return $item;
        }
    }
    return null;
}

function resolveMetaIdByName(?string $name, $rawMeta): ?int {
    if ($name == null || !is_array($rawMeta)) {
        return null;
    }
    foreach ($rawMeta as $row) {
        if (!is_array($row) || !isset($row["id"]) || !isset($row["name"])) {
            continue;
        }
        $rowName = html_entity_decode(strval($row["name"]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strcasecmp(trim($rowName), trim($name)) === 0) {
            return intval($row["id"]);
        }
    }
    return null;
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

                           function setProviderApiKeyBoxVisibility(boxId, isEnabled) {
                               var box = document.getElementById(boxId);
                               if (box) {
                                   box.style.display = isEnabled ? 'block' : 'none';
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

                           setOpenAiOptionsEnabled(document.getElementById('LOOKUP_USE_OPENAI') && document.getElementById('LOOKUP_USE_OPENAI').checked);
                           setProviderApiKeyBoxVisibility('upcDbApiKeyBox', document.getElementById('LOOKUP_USE_UPC_DATABASE') && document.getElementById('LOOKUP_USE_UPC_DATABASE').checked);
                           setProviderApiKeyBoxVisibility('openGtinApiKeyBox', document.getElementById('LOOKUP_USE_OPEN_GTIN_DATABASE') && document.getElementById('LOOKUP_USE_OPEN_GTIN_DATABASE').checked);
                           setProviderApiKeyBoxVisibility('discogsApiKeyBox', document.getElementById('LOOKUP_USE_DISCOGS') && document.getElementById('LOOKUP_USE_DISCOGS').checked);");

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

function generateApiKeyChangeScript(string $functionName, string $keyId, string $boxId): string {
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
                if (typeof setProviderApiKeyBoxVisibility === 'function') {
                    setProviderApiKeyBoxVisibility('" . $boxId . "', element.checked);
                }
            }";
}

function getProviderListItems(UiEditor $html): array {
    $config                                 = BBConfig::getInstance();
    $result                                 = array();

    $buildProviderListItem = function(string $htmlHeader, string $htmlBody, string $data = ""): string {
        if (strpos($htmlHeader, "mdl-checkbox") !== false) {
            $htmlBody = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $htmlBody;
        }
        return "<li data-id=\"$data\" class=\"mdl-list__item\" data-value=\"$data\" style=\"height:auto; min-height:72px; align-items:flex-start;\">"
            . "<span class=\"mdl-list__item-primary-content\" style=\"height:auto; white-space:normal; display:block;\">"
            . $htmlHeader
            . "<span class=\"mdl-list__item-sub-title\" style=\"white-space:normal; line-height:1.4;\">" . $htmlBody . "</span>"
            . "</span></li>\n";
    };

    $upcDbSettingsHtml = '<div id="upcDbApiKeyBox" style="display:' . (($config["LOOKUP_USE_UPC_DATABASE"] == "1") ? "block" : "none") . '; margin-top:8px;">'
        . (new EditFieldBuilder(
            'LOOKUP_UPC_DATABASE_KEY',
            'UPCDatabase.org API Key',
            $config["LOOKUP_UPC_DATABASE_KEY"],
            $html))
            ->required($config["LOOKUP_USE_UPC_DATABASE"])
            ->pattern('[A-Za-z0-9]{32}')
            ->disabled(!$config["LOOKUP_USE_UPC_DATABASE"])
            ->generate(true)
        . '</div>';

    $openGtinSettingsHtml = '<div id="openGtinApiKeyBox" style="display:' . (($config["LOOKUP_USE_OPEN_GTIN_DATABASE"] == "1") ? "block" : "none") . '; margin-top:8px;">'
        . (new EditFieldBuilder(
            'LOOKUP_OPENGTIN_KEY',
            'OpenGtinDb.org API Key',
            $config["LOOKUP_OPENGTIN_KEY"],
            $html))
            ->required($config["LOOKUP_USE_OPEN_GTIN_DATABASE"])
            ->pattern('[^%]{3,}')
            ->disabled(!$config["LOOKUP_USE_OPEN_GTIN_DATABASE"])
            ->generate(true)
        . '</div>';

    $discogsSettingsHtml = '<div id="discogsApiKeyBox" style="display:' . (($config["LOOKUP_USE_DISCOGS"] == "1") ? "block" : "none") . '; margin-top:8px;">'
        . (new EditFieldBuilder(
            'LOOKUP_DISCOGS_TOKEN',
            'discogs.com Access Token',
            $config["LOOKUP_DISCOGS_TOKEN"],
            $html))
            ->required($config["LOOKUP_USE_DISCOGS"])
            ->pattern('[A-Za-z0-9]{40}')
            ->disabled(!$config["LOOKUP_USE_DISCOGS"])
            ->generate(true)
        . '</div>';

    $openAiModelOptions = array(
        "gpt-5.4" => "gpt-5.4",
        "gpt-5.4-mini" => "gpt-5.4-mini",
        "gpt-5.3" => "gpt-5.3",
        "gpt-5.3-mini" => "gpt-5.3-mini",
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
    $testButtonHtml = $html->buildButton("testOpenAiLookupBtn", "Test OpenAI Lookup")
        ->setId("testOpenAiLookupBtn")
        ->setOnClick("return testOpenAiLookupRequest();")
        ->setRaised(true)
        ->setIsAccent(true)
        ->setDisabled(!$config["LOOKUP_USE_OPENAI"])
        ->generate(true);

    $openAiSettingsHtml = '<div id="openaiProviderOptions" style="display:' . (($config["LOOKUP_USE_OPENAI"] == "1") ? "block" : "none") . '; border:1px solid #ddd; padding:12px; margin-top:10px;">'
        . '<small><b>Hint:</b> You need an OpenAI developer account with API access and available credit/balance. Each lookup sends a request, consumes tokens and therefore costs money.</small><br><br>'
        . (new EditFieldBuilder(
            'LOOKUP_OPENAI_API_KEY',
            'OpenAI API Key (ChatGPT Lookup)',
            $config["LOOKUP_OPENAI_API_KEY"],
            $html))
            ->required($config["LOOKUP_USE_OPENAI"])
            ->pattern('.{20,}')
            ->type('password')
            ->disabled(!$config["LOOKUP_USE_OPENAI"])
            ->generate(true)
        . '<br>' . $selectHtml
        . '<br><b>OpenAI naming schema</b><br><small>Choose the exact components that must be returned. If one selected component cannot be determined, the lookup returns UNKNOWN.</small><br>'
        . $html->addCheckbox("LOOKUP_OPENAI_NAME_MANUFACTURER", "Brand / trade name", $config["LOOKUP_OPENAI_NAME_MANUFACTURER"], !$config["LOOKUP_USE_OPENAI"], false, true)
        . $html->addCheckbox("LOOKUP_OPENAI_NAME_PRODUCT", "Product name", $config["LOOKUP_OPENAI_NAME_PRODUCT"], !$config["LOOKUP_USE_OPENAI"], false, true)
        . $html->addCheckbox("LOOKUP_OPENAI_NAME_PACKSIZE", "Package size", $config["LOOKUP_OPENAI_NAME_PACKSIZE"], !$config["LOOKUP_USE_OPENAI"], false, true)
        . (new EditFieldBuilder(
            'LOOKUP_OPENAI_TEST_BARCODE_TEMP',
            'Test Barcode',
            '4306188348191',
            $html))
            ->pattern('[0-9]{8,18}')
            ->disabled(!$config["LOOKUP_USE_OPENAI"])
            ->generate(true)
        . '<div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:8px;"><div style="flex:0 0 auto;">' . $testButtonHtml . '</div></div>'
        . '<div id="openaiLookupTestStatus" style="display:none; margin:6px 0 8px 0; font-weight:600;"></div>'
        . '<div id="openaiLookupTestResult" style="display:block; white-space:pre-wrap; font-family:monospace; background:#f3f4f6; border:1px solid #d6d8dc; border-radius:4px; padding:10px; margin-top:6px; min-height:44px;"></div>'
        . '</div>';
    $result["id" . LOOKUP_ID_OPENFOODFACTS] = $buildProviderListItem($html->addCheckbox('LOOKUP_USE_OFF', 'Open Food Facts', $config["LOOKUP_USE_OFF"], false, false, true), "Uses OpenFoodFacts.org", LOOKUP_ID_OPENFOODFACTS);
    $result["id" . LOOKUP_ID_UPCDB]         = $buildProviderListItem($html->addCheckbox('LOOKUP_USE_UPC', 'UPC Item DB', $config["LOOKUP_USE_UPC"], false, false, true), "Uses UPCitemDB.com", LOOKUP_ID_UPCDB);
    $result["id" . LOOKUP_ID_ALBERTHEIJN]   = $buildProviderListItem($html->addCheckbox('LOOKUP_USE_AH', 'Albert Heijn', $config["LOOKUP_USE_AH"], false, false, true), "Uses AH.nl", LOOKUP_ID_ALBERTHEIJN);
    $result["id" . LOOKUP_ID_PLUS]          = $buildProviderListItem($html->addCheckbox('LOOKUP_USE_PLUS', 'Plus Supermarkt', $config["LOOKUP_USE_PLUS"], false, false, true), "Uses PLUS.nl", LOOKUP_ID_PLUS);
    $result["id" . LOOKUP_ID_JUMBO]         = $buildProviderListItem($html->addCheckbox('LOOKUP_USE_JUMBO', 'Jumbo', $config["LOOKUP_USE_JUMBO"], false, false, true), "Uses Jumbo.com (slow)", LOOKUP_ID_JUMBO);
    $result["id" . LOOKUP_ID_UPCDATABASE]   = $buildProviderListItem((new CheckBoxBuilder(
        "LOOKUP_USE_UPC_DATABASE",
        "UPC Database",
        $config["LOOKUP_USE_UPC_DATABASE"],
        $html)
    )->onCheckChanged(
        "handleUPCDBChange(this)",
        generateApiKeyChangeScript("handleUPCDBChange", "LOOKUP_UPC_DATABASE_KEY", "upcDbApiKeyBox"))
        ->generate(true), "Uses UPCDatabase.org" . $upcDbSettingsHtml, LOOKUP_ID_UPCDATABASE);

    $result["id" . LOOKUP_ID_OPENGTINDB] = $buildProviderListItem((new CheckBoxBuilder(
        "LOOKUP_USE_OPEN_GTIN_DATABASE",
        "Open EAN / GTIN Database",
        $config["LOOKUP_USE_OPEN_GTIN_DATABASE"],
        $html)
    )->onCheckChanged(
        "handleOpenGtinChange(this)",
        generateApiKeyChangeScript("handleOpenGtinChange", "LOOKUP_OPENGTIN_KEY", "openGtinApiKeyBox"))
        ->generate(true), "Uses OpenGtinDb.org" . $openGtinSettingsHtml, LOOKUP_ID_OPENGTINDB);

    $result["id" . LOOKUP_ID_DISCOGS]   = $buildProviderListItem((new CheckBoxBuilder(
        "LOOKUP_USE_DISCOGS",
        "Discogs Database",
        $config["LOOKUP_USE_DISCOGS"],
        $html)
    )->onCheckChanged(
        "handleDiscogsChange(this)",
        generateApiKeyChangeScript("handleDiscogsChange", "LOOKUP_DISCOGS_TOKEN", "discogsApiKeyBox"))
        ->generate(true), "Uses Discogs.com" . $discogsSettingsHtml, LOOKUP_ID_DISCOGS);

    $result["id" . LOOKUP_ID_OPENAI] = $buildProviderListItem((new CheckBoxBuilder(
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
        ->generate(true), "Uses OpenAI ChatGPT API for barcode name lookup" . $openAiSettingsHtml, LOOKUP_ID_OPENAI);

    $bbServerSubtitle                    = "Uses " . BarcodeFederation::HOST_READABLE;
    if (!$config["BBUDDY_SERVER_ENABLED"])
        $bbServerSubtitle = "Enable Federation for this feature";
    $result["id" . LOOKUP_ID_FEDERATION] = $buildProviderListItem($html->addCheckbox('LOOKUP_USE_BBUDDY_SERVER', 'Barcode Buddy Federation', $config["LOOKUP_USE_BBUDDY_SERVER"], !$config["BBUDDY_SERVER_ENABLED"], false, true), $bbServerSubtitle, LOOKUP_ID_FEDERATION);
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
