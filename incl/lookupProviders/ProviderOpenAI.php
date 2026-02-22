<?php

/**
 * Barcode Buddy for Grocy
 *
 * PHP version 7
 *
 * LICENSE: This source file is subject to version 3.0 of the GNU General
 * Public License v3.0 that is attached to this project.
 *
 * @author     OpenAI
 * @copyright  2026
 * @license    https://www.gnu.org/licenses/gpl-3.0.en.html  GNU GPL v3.0
 */

require_once __DIR__ . "/../api.inc.php";

class ProviderOpenAI extends LookupProvider {

    private const OPENAI_ENDPOINT = "https://api.openai.com/v1/responses";
    private const DEFAULT_OPENAI_MODEL = "gpt-4.1-mini";
    private ?string $lastErrorMessage = null;
    private ?string $lastResponseText = null;

    function __construct(string $apiKey = null) {
        parent::__construct($apiKey);
        $this->providerName      = "OpenAI";
        $this->providerConfigKey = "LOOKUP_USE_OPENAI";
        if ($this->apiKey == null) {
            $this->apiKey = BBConfig::getInstance()["LOOKUP_OPENAI_API_KEY"];
        }
    }

    /**
     * Looks up a barcode using OpenAI Responses API with web search tool
     * @param string $barcode The barcode to lookup
     * @return array|null
     */
    public function lookupBarcode(string $barcode): ?array {
        $this->lastErrorMessage = null;
        $this->lastResponseText = null;
        if (!$this->isProviderEnabled()) {
            $this->lastErrorMessage = "OpenAI lookup provider is disabled";
            return null;
        }
        if ($this->apiKey == null || trim($this->apiKey) === "") {
            $this->lastErrorMessage = "OpenAI API key is missing";
            return null;
        }
        $apiKey = trim($this->apiKey);

        $result = $this->executeResponsesLookup($barcode, $apiKey, "web_search");
        if ($this->isUnsupportedWebSearchToolError($result)) {
            $result = $this->executeResponsesLookup($barcode, $apiKey, "web_search_preview");
        }

        if (!is_array($result)) {
            $this->lastErrorMessage = "No valid response received from OpenAI";
            return null;
        }
        if (isset($result["error"]["message"])) {
            $this->lastErrorMessage = sanitizeString($result["error"]["message"]);
            API::logError("OpenAI lookup error: " . $this->lastErrorMessage);
            return null;
        }
        $content = $this->extractResponseText($result);
        if ($content == null) {
            $this->lastErrorMessage = "OpenAI response did not contain a message";
            return null;
        }
        $this->lastResponseText = $content;

        $name = $this->buildNameFromAiResponse($content, $barcode);
        if ($name === null) {
            $this->lastErrorMessage = "OpenAI returned UNKNOWN / no confident match";
            return null;
        }

        return self::createReturnArray(sanitizeString($name));
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    public function getLastResponseText(): ?string {
        return $this->lastResponseText;
    }

    private function buildPrompt(string $barcode): string {
        $config = BBConfig::getInstance();
        $schema = array();
        $requiredRules = array();

        if ($config["LOOKUP_OPENAI_NAME_MANUFACTURER"] == "1") {
            array_push($schema, "brand / trade name");
            array_push($requiredRules, "- Include the brand / trade name exactly once");
        }
        if ($config["LOOKUP_OPENAI_NAME_PRODUCT"] == "1") {
            array_push($schema, "product name");
            array_push($requiredRules, "- Include the product name exactly once");
        }
        if ($config["LOOKUP_OPENAI_NAME_PACKSIZE"] == "1") {
            array_push($schema, "package size");
            array_push($requiredRules, "- Include the package size exactly once");
        }
        if (count($schema) === 0) {
            array_push($schema, "product name");
            array_push($requiredRules, "- Include the product name exactly once");
        }

        $schemaString = implode(" + ", $schema);
        $requiredRulesString = implode("\n", $requiredRules);
        $jsonKeys = "brand, product_name, package_size";

        return "You must search the internet for the retail product with EAN/UPC barcode " . $barcode . ".\n"
            . "Use the web search tool now. Do not answer from memory.\n"
            . "Search for the exact barcode string " . $barcode . " and verify at least one source explicitly references this barcode.\n"
            . "Only return a product if the barcode matches exactly.\n"
            . "Return ONLY valid JSON (no markdown, no code fences, no commentary).\n"
            . "JSON object keys must be exactly: " . $jsonKeys . "\n"
            . "Use null for any component that is not requested by the schema.\n"
            . "Requested schema and order for final name construction (server-side): " . $schemaString . "\n"
            . "Component rules (mandatory):\n"
            . $requiredRulesString . "\n"
            . "- Do NOT include unrequested components in requested fields\n"
            . "- brand = brand / trade name only\n"
            . "- product_name = product description only (without brand unless it is part of the official product name)\n"
            . "- package_size = package size only\n"
            . "- If ANY requested component cannot be identified with high confidence, return exactly: UNKNOWN\n"
            . "Package size rules (mandatory if package size is included):\n"
            . "- Normalize units: use grams (g) for everything below 1 kg\n"
            . "- Use kilograms (kg) for 1 kg or more\n"
            . "- Examples: 300g, 750g, 1kg, 1.5kg, 2kg\n"
            . "If unknown, return exactly: UNKNOWN";
    }

    private function executeResponsesLookup(string $barcode, string $apiKey, string $toolType) {
        $payload = array(
            "model" => $this->getConfiguredModel(),
            "temperature" => 0,
            "tools" => array(
                array(
                    "type" => $toolType
                )
            ),
            "tool_choice" => "auto",
            "input" => $this->buildPrompt($barcode)
        );

        return $this->execute(
            self::OPENAI_ENDPOINT,
            METHOD_POST,
            null,
            null,
            array(
                "Authorization" => "Bearer " . $apiKey
            ),
            true,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function getConfiguredModel(): string {
        $configuredModel = BBConfig::getInstance()["LOOKUP_OPENAI_MODEL"];
        if ($configuredModel == null || trim($configuredModel) === "") {
            return self::DEFAULT_OPENAI_MODEL;
        }
        return trim($configuredModel);
    }

    private function isUnsupportedWebSearchToolError($result): bool {
        if (!is_array($result) || !isset($result["error"]["message"])) {
            return false;
        }
        $message = strtolower(strval($result["error"]["message"]));
        return strpos($message, "web_search") !== false
            && (strpos($message, "unsupported") !== false
                || strpos($message, "unknown") !== false
                || strpos($message, "invalid") !== false);
    }

    private function extractResponseText(array $result): ?string {
        if (isset($result["output_text"]) && is_string($result["output_text"])) {
            return $result["output_text"];
        }

        if (!isset($result["output"]) || !is_array($result["output"])) {
            return null;
        }

        $parts = array();
        foreach ($result["output"] as $outputItem) {
            if (!is_array($outputItem) || !isset($outputItem["content"]) || !is_array($outputItem["content"])) {
                continue;
            }
            foreach ($outputItem["content"] as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }
                if (isset($contentItem["text"]) && is_string($contentItem["text"])) {
                    array_push($parts, $contentItem["text"]);
                    continue;
                }
                if (isset($contentItem["type"]) && $contentItem["type"] === "output_text" && isset($contentItem["text"]) && is_string($contentItem["text"])) {
                    array_push($parts, $contentItem["text"]);
                }
            }
        }

        if (count($parts) === 0) {
            return null;
        }
        return implode(" ", $parts);
    }

    private function buildNameFromAiResponse(string $content, string $barcode): ?string {
        $trimmed = trim($content);
        if ($trimmed === "" || strtoupper($trimmed) === "UNKNOWN") {
            return null;
        }

        $jsonData = $this->extractJsonObjectFromText($trimmed);
        if (!is_array($jsonData)) {
            // Fallback to legacy plain-text behavior if model ignored JSON instruction
            $name = trim(str_replace(array("\r", "\n"), " ", $trimmed));
            $name = trim($name, " \t\n\r\0\x0B\"'");
            return ($name === "" || strtoupper($name) === "UNKNOWN") ? null : $name;
        }

        $config = BBConfig::getInstance();
        $parts = array();

        if ($config["LOOKUP_OPENAI_NAME_MANUFACTURER"] == "1") {
            $brand = $this->normalizeJsonField($jsonData, "brand");
            if ($brand == null) {
                return null;
            }
            array_push($parts, $brand);
        }
        if ($config["LOOKUP_OPENAI_NAME_PRODUCT"] == "1") {
            $product = $this->normalizeJsonField($jsonData, "product_name");
            if ($product == null) {
                return null;
            }
            array_push($parts, $product);
        }
        if ($config["LOOKUP_OPENAI_NAME_PACKSIZE"] == "1") {
            $pack = $this->normalizeJsonField($jsonData, "package_size");
            if ($pack == null) {
                return null;
            }
            array_push($parts, $pack);
        }

        if (count($parts) === 0) {
            $product = $this->normalizeJsonField($jsonData, "product_name");
            if ($product == null) {
                return null;
            }
            array_push($parts, $product);
        }

        // Collapse whitespace and remove common separators between components if the model still inserted them.
        $name = implode(" ", $parts);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name);
        if ($name === "") {
            return null;
        }
        return $name;
    }

    private function extractJsonObjectFromText(string $content): ?array {
        $json = json_decode($content, true);
        if (is_array($json)) {
            return $json;
        }

        if (preg_match('/\{.*\}/s', $content, $matches) !== 1) {
            return null;
        }
        $json = json_decode($matches[0], true);
        return is_array($json) ? $json : null;
    }

    private function normalizeJsonField(array $jsonData, string $key): ?string {
        if (!array_key_exists($key, $jsonData) || $jsonData[$key] === null) {
            return null;
        }
        if (!is_string($jsonData[$key])) {
            return null;
        }
        $value = trim($jsonData[$key]);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $value = preg_replace('/\s+/', ' ', $value);
        return ($value === "") ? null : $value;
    }

    private function flattenContentArray(array $content): string {
        $parts = array();
        foreach ($content as $item) {
            if (is_array($item) && isset($item["type"]) && $item["type"] === "text" && isset($item["text"])) {
                array_push($parts, $item["text"]);
            }
        }
        return implode(" ", $parts);
    }
}
