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

    private const OPENAI_ENDPOINT = "https://api.openai.com/v1/chat/completions";
    private const OPENAI_MODEL = "gpt-4o-mini";

    function __construct(string $apiKey = null) {
        parent::__construct($apiKey);
        $this->providerName      = "OpenAI";
        $this->providerConfigKey = "LOOKUP_USE_OPENAI";
        if ($this->apiKey == null) {
            $this->apiKey = BBConfig::getInstance()["LOOKUP_OPENAI_API_KEY"];
        }
    }

    /**
     * Looks up a barcode using OpenAI Chat Completions
     * @param string $barcode The barcode to lookup
     * @return array|null
     */
    public function lookupBarcode(string $barcode): ?array {
        if (!$this->isProviderEnabled()) {
            return null;
        }
        if ($this->apiKey == null || trim($this->apiKey) === "") {
            return null;
        }
        $apiKey = trim($this->apiKey);

        $payload = array(
            "model" => self::OPENAI_MODEL,
            "temperature" => 0.1,
            "max_tokens" => 120,
            "messages" => array(
                array(
                    "role" => "system",
                    "content" => "You identify retail products from EAN/UPC barcodes. Respond with exactly one product name line only. If the barcode cannot be identified with reasonable confidence, respond exactly with UNKNOWN."
                ),
                array(
                    "role" => "user",
                    "content" => $this->buildPrompt($barcode)
                )
            )
        );

        $result = $this->execute(
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

        if (!is_array($result)) {
            return null;
        }
        if (isset($result["error"]["message"])) {
            API::logError("OpenAI lookup error: " . sanitizeString($result["error"]["message"]));
            return null;
        }
        if (!isset($result["choices"][0]["message"]["content"])) {
            return null;
        }

        $content = $result["choices"][0]["message"]["content"];
        if (is_array($content)) {
            $content = $this->flattenContentArray($content);
        }
        if (!is_string($content)) {
            return null;
        }

        $name = trim(str_replace(array("\r", "\n"), " ", $content));
        $name = trim($name, " \t\n\r\0\x0B\"'");
        if ($name === "" || strtoupper($name) === "UNKNOWN") {
            return null;
        }

        return self::createReturnArray(sanitizeString($name));
    }

    private function buildPrompt(string $barcode): string {
        $config = BBConfig::getInstance();
        $schema = array();

        if ($config["LOOKUP_OPENAI_NAME_MANUFACTURER"] == "1") {
            array_push($schema, "manufacturer");
        }
        if ($config["LOOKUP_OPENAI_NAME_PRODUCT"] == "1") {
            array_push($schema, "product name");
        }
        if ($config["LOOKUP_OPENAI_NAME_PACKSIZE"] == "1") {
            array_push($schema, "package size");
        }
        if (count($schema) === 0) {
            array_push($schema, "product name");
        }

        return "Look up the product for barcode: " . $barcode . ".\n"
            . "Return only one plain text line using this naming schema and order: "
            . implode(" + ", $schema) . ".\n"
            . "Do not add commentary, confidence, punctuation wrappers, or extra fields.\n"
            . "If unknown, return exactly: UNKNOWN";
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
