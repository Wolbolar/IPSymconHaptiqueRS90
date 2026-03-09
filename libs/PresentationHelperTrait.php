<?php
declare(strict_types=1);

trait PresentationHelperTrait
{
    // ---- Symcon presentation GUIDs ----
    protected const PRESENTATION_SLIDER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    protected const PRESENTATION_LEGACY = '{4153A8D4-5C33-C65F-C1F3-7B61AAF99B1C}';
    protected const PRESENTATION_VALUE_DISPLAY = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    protected const PRESENTATION_VALUE_INPUT = '{6F477326-1683-A2FD-D2E7-477F366ECB62}';
    protected const PRESENTATION_WEB_CONTENT = '{9DE1D610-5106-97FB-714D-1AADEDF8377A}';
    protected const PRESENTATION_COLOR = '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}';
    protected const PRESENTATION_DATE_TIME = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
    protected const PRESENTATION_SWITCH = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
    protected const PRESENTATION_SHUTTER = '{6075FC22-69AF-B110-3749-C24138883082}';
    protected const PRESENTATION_ENUMERATION = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
    protected const PRESENTATION_DURATION = '{08A6AF76-394E-D354-48D5-BFC690488E4E}';

    // ---- Symcon template GUIDs ----
    protected const TEMPLATE_DATE = '{B4C70F3E-6613-DA1A-7279-5DEE8DEB1B24}';
    protected const TEMPLATE_TIME = '{362DA268-56A2-E771-5E53-17E38B5D82E6}';
    protected const TEMPLATE_DATE_TIME = '{BB0E9933-0403-BD3A-D1C9-255646934B00}';
    protected const TEMPLATE_SLIDER_ROOM_TEMPERATURE = '{868B087E-A38D-2155-EBE0-157AFBBF9E8C}';
    protected const TEMPLATE_SLIDER_COLOR_TEMPERATURE = '{66062309-21A9-26C0-213F-775C52E1473B}';
    protected const TEMPLATE_SLIDER_ENERGY = '{BC799412-0C66-551F-CAEC-7566F5D52BD9}';
    protected const TEMPLATE_SLIDER_POWER = '{8EC19DF0-89FB-A77E-ED7D-047A949CF292}';
    protected const TEMPLATE_SHUTTER_LAMELLA_RIGHT = '{3BE75DE9-7D84-C082-2E77-9ED3AEE04D63}';
    protected const TEMPLATE_SHUTTER_LAMELLA_LEFT = '{22A0DF9C-C200-154A-641B-3A3CB096DB6D}';
    protected const TEMPLATE_VALUE_DISPLAY_ROOM_TEMPERATURE = '{90AF8F8F-183F-BBFD-E078-35FAB6DCFE4F}';
    protected const TEMPLATE_VALUE_DISPLAY_POWER = '{2FED3D39-073D-6037-901B-2586A1AB5569}';
    protected const TEMPLATE_VALUE_DISPLAY_ENERGY = '{C899FCFA-063E-897E-9DA4-28ADD278EED5}';
    protected const TEMPLATE_VALUE_DISPLAY_BATTERY = '{7BD38CF5-07F2-5B5B-8F7F-15398B823BFC}';
    protected const TEMPLATE_VALUE_DISPLAY_BATTERY_COLOR = '{C90EF36A-165E-D0B0-032C-F468F483D42B}';
    protected const TEMPLATE_COLOR_RAINBOW = '{0C711895-2F8E-DBFE-1700-84173491D229}';
    protected const TEMPLATE_COLOR_FOREST = '{A7467E68-5C39-5BD9-C0C8-BCE6004FEEAA}';

    // ---- Generic root keys ----
    protected const PRESENTATION_KEY = 'PRESENTATION';
    protected const PARAM_ICON = 'ICON';

    // ---- Date/Time root parameters ----
    protected const PARAM_DATE = 'DATE';
    protected const PARAM_MONTH_TEXT = 'MONTH_TEXT';
    protected const PARAM_DAY_OF_THE_WEEK = 'DAY_OF_THE_WEEK';
    protected const PARAM_TIME = 'TIME';

    // ---- Duration root parameters ----
    protected const PARAM_DAYS = 'DAYS';
    protected const PARAM_HOURS = 'HOURS';
    protected const PARAM_MINUTES = 'MINUTES';
    protected const PARAM_SECONDS = 'SECONDS';
    protected const PARAM_COUNTDOWN_TYPE = 'COUNTDOWN_TYPE';
    protected const PARAM_FORMAT = 'FORMAT';
    protected const PARAM_MILLISECONDS = 'MILLISECONDS';

    // ---- Color root parameters ----
    protected const PARAM_ALPHA_CHANNEL = 'ALPHA_CHANNEL';
    protected const PARAM_ENCODING = 'ENCODING';
    protected const PARAM_PRESET_VALUES = 'PRESET_VALUES';
    protected const PARAM_COLOR_SPACE = 'COLOR_SPACE';
    protected const PARAM_CUSTOM_COLOR_SPACE = 'CUSTOM_COLOR_SPACE';
    protected const PARAM_COLOR_CURVE = 'COLOR_CURVE';
    protected const PARAM_CUSTOM_COLOR_CURVE = 'CUSTOM_COLOR_CURVE';

    // ---- Shutter root parameters ----
    protected const PARAM_USAGE_TYPE = 'USAGE_TYPE';
    protected const PARAM_OPEN_OUTSIDE_VALUE = 'OPEN_OUTSIDE_VALUE';
    protected const PARAM_CLOSE_INSIDE_VALUE = 'CLOSE_INSIDE_VALUE';
    protected const PARAM_MAX_ROTATION_INSIDE = 'MAX_ROTATION_INSIDE';
    protected const PARAM_MAX_ROTATION_OUTSIDE = 'MAX_ROTATION_OUTSIDE';
    protected const PARAM_SUN_POSITION = 'SUN_POSITION';

    // ---- Switch root parameters ----
    protected const PARAM_USE_ICON_FALSE = 'USE_ICON_FALSE';
    protected const PARAM_ICON_TRUE = 'ICON_TRUE';
    protected const PARAM_ICON_FALSE = 'ICON_FALSE';
    protected const PARAM_GLOW_COLOR = 'GLOW_COLOR';
    protected const PARAM_GLOW_INTENSITY = 'GLOW_INTENSITY';
    protected const PARAM_USAGE_TYPE_SWITCH = 'USAGE_TYPE';

    // ---- Slider root parameters ----
    protected const PARAM_MIN = 'MIN';
    protected const PARAM_MAX = 'MAX';
    protected const PARAM_STEP_SIZE = 'STEP_SIZE';
    protected const PARAM_GRADIENT_TYPE = 'GRADIENT_TYPE';
    protected const PARAM_CUSTOM_GRADIENT = 'CUSTOM_GRADIENT';
    protected const PARAM_USAGE_TYPE_SLIDER = 'USAGE_TYPE';
    protected const PARAM_PREFIX = 'PREFIX';
    protected const PARAM_SUFFIX = 'SUFFIX';
    protected const PARAM_PERCENTAGE = 'PERCENTAGE';
    protected const PARAM_THOUSANDS_SEPARATOR = 'THOUSANDS_SEPARATOR';
    protected const PARAM_DIGITS = 'DIGITS';
    protected const PARAM_DECIMAL_SEPARATOR = 'DECIMAL_SEPARATOR';
    protected const PARAM_INTERVALS_ACTIVE = 'INTERVALS_ACTIVE';
    protected const PARAM_INTERVALS = 'INTERVALS';

    // ---- Web content root parameters ----
    protected const PARAM_HTML_TYPE = 'HTML_TYPE';
    protected const PARAM_PADDING = 'PADDING';

    // ---- Value display root parameters ----
    protected const PARAM_USAGE_TYPE_VALUE_DISPLAY = 'USAGE_TYPE';
    protected const PARAM_PERCENTAGE_VALUE_DISPLAY = 'PERCENTAGE';
    protected const PARAM_MIN_VALUE_DISPLAY = 'MIN';
    protected const PARAM_MAX_VALUE_DISPLAY = 'MAX';
    protected const PARAM_THOUSANDS_SEPARATOR_VALUE_DISPLAY = 'THOUSANDS_SEPARATOR';
    protected const PARAM_DIGITS_VALUE_DISPLAY = 'DIGITS';
    protected const PARAM_DECIMAL_SEPARATOR_VALUE_DISPLAY = 'DECIMAL_SEPARATOR';
    protected const PARAM_INTERVALS_ACTIVE_VALUE_DISPLAY = 'INTERVALS_ACTIVE';
    protected const PARAM_INTERVALS_VALUE_DISPLAY = 'INTERVALS';

    // ---- Value input root parameters ----
    protected const PARAM_PREFIX_VALUE_INPUT = 'PREFIX';
    protected const PARAM_SUFFIX_VALUE_INPUT = 'SUFFIX';
    protected const PARAM_MULTILINE = 'MULTILINE';

    // ---- Date display values ----
    protected const DATE_NONE = 0;
    protected const DATE_YEAR_MONTH_DAY = 1;
    protected const DATE_MONTH_DAY = 2;
    protected const DATE_YEAR_DAY = 3;

    // ---- Time display values ----
    protected const TIME_NONE = 0;
    protected const TIME_HOURS_MINUTES = 1;
    protected const TIME_HOURS_MINUTES_SECONDS = 2;

    // ---- Duration countdown type values ----
    protected const DURATION_COUNTDOWN_VALUE_IN_VARIABLE = 0;
    protected const DURATION_COUNTDOWN_UNTIL_VARIABLE_VALUE = 1;
    protected const DURATION_COUNTDOWN_SINCE_VARIABLE_VALUE = 2;

    // ---- Duration format values ----
    protected const DURATION_FORMAT_SECONDS_ONLY = 0;
    protected const DURATION_FORMAT_MINUTES_SECONDS = 1;
    protected const DURATION_FORMAT_HOURS_MINUTES_SECONDS = 2;

    // ---- Color encoding values ----
    protected const COLOR_ENCODING_RGB = 0;
    protected const COLOR_ENCODING_CMYK = 1;
    protected const COLOR_ENCODING_HSV = 2;
    protected const COLOR_ENCODING_HSL = 3;
    protected const COLOR_ENCODING_XY = 4;

    // ---- Color space values ----
    protected const COLOR_SPACE_CUSTOM = 0;
    protected const COLOR_SPACE_SRGB = 1;
    protected const COLOR_SPACE_ADOBE_RGB = 2;
    protected const COLOR_SPACE_DCI_P3 = 3;
    protected const COLOR_SPACE_REC2020 = 4;

    // ---- Color curve values ----
    protected const COLOR_CURVE_NONE = 0;
    protected const COLOR_CURVE_CUSTOM = 1;
    protected const COLOR_CURVE_DAYLIGHT = 2;
    protected const COLOR_CURVE_DAYLIGHT_SPRING = 3;
    protected const COLOR_CURVE_DAYLIGHT_SUMMER = 4;
    protected const COLOR_CURVE_DAYLIGHT_WINTER = 5;

    // ---- Shutter usage type values ----
    protected const SHUTTER_USAGE_OPEN = 0;
    protected const SHUTTER_USAGE_ROTATION = 1;

    // ---- Shutter sun position values ----
    protected const SHUTTER_SUN_POSITION_LEFT = 0;
    protected const SHUTTER_SUN_POSITION_RIGHT = 1;
    protected const SHUTTER_SUN_POSITION_NONE = 2;

    // ---- Switch usage type values ----
    protected const SWITCH_USAGE_ON_OFF = 0;
    protected const SWITCH_USAGE_MUTE = 1;
    protected const SWITCH_USAGE_NONE = 2;

    // ---- Slider gradient type values ----
    protected const SLIDER_GRADIENT_STANDARD = 0;
    protected const SLIDER_GRADIENT_TEMPERATURE = 1;
    protected const SLIDER_GRADIENT_COLOR_TEMPERATURE = 2;
    protected const SLIDER_GRADIENT_CUSTOM = 3;

    // ---- Slider usage type values ----
    protected const SLIDER_USAGE_TEMPERATURE = 0;
    protected const SLIDER_USAGE_COLOR_TEMPERATURE = 1;
    protected const SLIDER_USAGE_INTENSITY = 2;
    protected const SLIDER_USAGE_VOLUME = 3;
    protected const SLIDER_USAGE_PROGRESS = 4;
    protected const SLIDER_USAGE_NONE = 5;

    // ---- Web content type values ----
    protected const WEB_CONTENT_HTML = 0;
    protected const WEB_CONTENT_WEBSITE = 1;

    // ---- Value display usage type values ----
    protected const VALUE_DISPLAY_USAGE_NONE = 0;
    protected const VALUE_DISPLAY_USAGE_TEMPERATURE = 1;

    /**
     * Build a value input presentation.
     *
     * Supported root parameters according to Symcon documentation:
     * - PREFIX
     * - SUFFIX
     * - MULTILINE
     */
    protected function BuildValueInputPresentation(array $parameters = []): array
    {
        return $this->BuildPresentation(
            self::PRESENTATION_VALUE_INPUT,
            $parameters
        );
    }

    /**
     * Build a value display presentation.
     *
     * Supported root parameters according to Symcon documentation:
     * - USAGE_TYPE (0 none, 1 temperature)
     * - PERCENTAGE (true percentage, false absolute)
     * - MIN
     * - MAX
     * - THOUSANDS_SEPARATOR
     * - DIGITS
     * - DECIMAL_SEPARATOR
     * - INTERVALS_ACTIVE
     * - INTERVALS (JSON encoded automatically)
     */
    protected function BuildValueDisplayPresentation(array $parameters = []): array
    {
        return $this->BuildPresentation(
            self::PRESENTATION_VALUE_DISPLAY,
            $parameters,
            [self::PARAM_INTERVALS_VALUE_DISPLAY]
        );
    }

    // ---- Enumeration root parameters ----
    protected const PARAM_OPTIONS = 'OPTIONS';
    protected const PARAM_LAYOUT = 'LAYOUT';
    protected const PARAM_DISPLAY = 'DISPLAY';

    // ---- Enumeration layout values ----
    protected const ENUM_LAYOUT_COLUMN = 0;
    protected const ENUM_LAYOUT_ROW = 1;
    protected const ENUM_LAYOUT_GRID = 2;

    // ---- Enumeration display values ----
    protected const ENUM_DISPLAY_CAPTION = 0;
    protected const ENUM_DISPLAY_ICON = 1;
    protected const ENUM_DISPLAY_CAPTION_AND_ICON = 2;

    // ---- Enumeration option keys ----
    protected const OPTION_VALUE = 'Value';
    protected const OPTION_CAPTION = 'Caption';
    protected const OPTION_ICON_ACTIVE = 'IconActive';
    protected const OPTION_ICON_VALUE = 'IconValue';
    protected const OPTION_COLOR = 'Color';

    /**
     * Build a custom variable presentation array.
     *
     * Some nested parameters (for example OPTIONS on enumerations) must themselves
     * be JSON strings according to the Symcon documentation.
     */
    protected function BuildPresentation(string $presentation, array $parameters = [], array $jsonKeys = []): array
    {
        $payload = array_merge([
            self::PRESENTATION_KEY => $presentation
        ], $parameters);

        foreach ($jsonKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            if (is_array($payload[$key]) || is_object($payload[$key])) {
                $json = json_encode($payload[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    if (method_exists($this, 'SendDebug')) {
                        $this->SendDebug(__FUNCTION__, 'Failed to encode presentation sub-key: ' . $key, 0);
                    }
                    unset($payload[$key]);
                    continue;
                }
                $payload[$key] = $json;
            }
        }

        if (method_exists($this, 'SendDebug')) {
            $debugPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($debugPayload !== false) {
                $this->SendDebug(__FUNCTION__, 'Built presentation: ' . $debugPayload, 0);
            }
        }

        return $payload;
    }

    /**
     * Build a web content presentation.
     *
     * Supported root parameters according to Symcon documentation:
     * - HTML_TYPE (0 HTML content, 1 website)
     * - PADDING (false keep margins, true remove margins)
     */
    protected function BuildWebContentPresentation(array $parameters = []): array
    {
        return $this->BuildPresentation(
            self::PRESENTATION_WEB_CONTENT,
            $parameters
        );
    }

    /**
     * Build a slider presentation.
     *
     * Supported root parameters according to Symcon documentation:
     * - MIN
     * - MAX
     * - STEP_SIZE
     * - GRADIENT_TYPE (0 standard, 1 temperature, 2 color temperature, 3 custom)
     * - CUSTOM_GRADIENT (JSON encoded automatically)
     * - USAGE_TYPE (0 temperature, 1 color temperature, 2 intensity, 3 volume, 4 progress, 5 none)
     * - PREFIX
     * - SUFFIX
     * - PERCENTAGE (false absolute, true percentage)
     * - THOUSANDS_SEPARATOR
     * - DIGITS
     * - DECIMAL_SEPARATOR
     * - ICON
     * - INTERVALS_ACTIVE
     * - INTERVALS (JSON encoded automatically)
     */
    protected function BuildSliderPresentation(array $parameters = []): array
    {
        return $this->BuildPresentation(
            self::PRESENTATION_SLIDER,
            $parameters,
            [
                self::PARAM_CUSTOM_GRADIENT,
                self::PARAM_INTERVALS
            ]
        );
    }

    /**
     * Build a switch presentation.
     *
     * Supported root parameters according to Symcon documentation:
     * - USE_ICON_FALSE (false same icon for both states, true separate false icon)
     * - ICON_TRUE
     * - ICON_FALSE
     * - GLOW_COLOR
     * - GLOW_INTENSITY
     * - USAGE_TYPE (0 on/off, 1 mute, 2 none)
     */
    protected function BuildSwitchPresentation(array $parameters = []): array
    {
        return $this->BuildPresentation(
            self::PRESENTATION_SWITCH,
            $parameters
        );
    }

    /**
     * Build a shutter presentation.
     *
     * Supported root parameters according to Symcon documentation:
     * - ICON
     * - USAGE_TYPE (0 open, 1 rotation)
     * - OPEN_OUTSIDE_VALUE
     * - CLOSE_INSIDE_VALUE
     * - MAX_ROTATION_INSIDE
     * - MAX_ROTATION_OUTSIDE
     * - SUN_POSITION (0 left, 1 right, 2 none)
     */
    protected function BuildShutterPresentation(array $parameters = []): array
    {
        return $this->BuildPresentation(
            self::PRESENTATION_SHUTTER,
            $parameters
        );
    }

    /**
     * Build a date/time presentation.
     *
     * Supported root parameters according to Symcon documentation:
     * - ICON
     * - DATE (0 none, 1 year/month/day, 2 month/day, 3 year/day)
     * - MONTH_TEXT (false number, true text)
     * - DAY_OF_THE_WEEK (false hidden, true visible)
     * - TIME (0 none, 1 hours/minutes, 2 hours/minutes/seconds)
     */
    protected function BuildDateTimePresentation(array $parameters = []): array
    {
        return $this->BuildPresentation(
            self::PRESENTATION_DATE_TIME,
            $parameters
        );
    }

    /**
     * Build a duration presentation.
     *
     * Supported root parameters according to Symcon documentation:
     * - ICON
     * - DAYS
     * - HOURS
     * - MINUTES
     * - SECONDS
     * - COUNTDOWN_TYPE (0 value in variable, 1 duration until variable value, 2 duration since variable value)
     * - FORMAT (0 seconds only, 1 minutes and seconds, 2 hours, minutes and seconds)
     * - MILLISECONDS (false hidden, true visible)
     */
    protected function BuildDurationPresentation(array $parameters = []): array
    {
        return $this->BuildPresentation(
            self::PRESENTATION_DURATION,
            $parameters
        );
    }

    /**
     * Build a color presentation.
     *
     * Supported root parameters according to Symcon documentation:
     * - ICON
     * - ALPHA_CHANNEL (false hidden, true visible)
     * - ENCODING (0 RGB, 1 CMYK, 2 HSV, 3 HSL, 4 xy)
     * - PRESET_VALUES (JSON encoded automatically; not available for xy encoding)
     * - COLOR_SPACE (0 custom, 1 sRGB, 2 AdobeRGB, 3 DCI-P3, 4 Rec2020)
     * - CUSTOM_COLOR_SPACE (JSON encoded automatically)
     * - COLOR_CURVE (0 none, 1 custom, 2 daylight, 3 daylight spring, 4 daylight summer, 5 daylight winter)
     * - CUSTOM_COLOR_CURVE (JSON encoded automatically)
     */
    protected function BuildColorPresentation(array $parameters = []): array
    {
        return $this->BuildPresentation(
            self::PRESENTATION_COLOR,
            $parameters,
            [
                self::PARAM_PRESET_VALUES,
                self::PARAM_CUSTOM_COLOR_SPACE,
                self::PARAM_CUSTOM_COLOR_CURVE
            ]
        );
    }

    /**
     * Build an enumeration presentation.
     *
     * Supported root parameters according to Symcon documentation:
     * - ICON
     * - OPTIONS (JSON encoded automatically)
     * - LAYOUT (0 column, 1 row, 2 grid)
     * - DISPLAY (0 caption, 1 icon, 2 caption and icon)
     */
    protected function BuildEnumerationPresentation(array $options, array $parameters = []): array
    {
        return $this->BuildPresentation(
            self::PRESENTATION_ENUMERATION,
            array_merge([
                self::PARAM_OPTIONS => $options
            ], $parameters),
            [self::PARAM_OPTIONS]
        );
    }

    /**
     * Build a single enumeration option.
     *
     * Only non-null optional values are added to keep the payload compact.
     */
    protected function BuildEnumerationOption(
        bool|int|float|string $value,
        string                $caption,
        ?bool                 $iconActive = null,
        ?string               $iconValue = null,
        ?int                  $color = null
    ): array
    {
        return [
            self::OPTION_VALUE => $value,
            self::OPTION_CAPTION => $caption,
            self::OPTION_ICON_ACTIVE => $iconActive ?? false,
            self::OPTION_ICON_VALUE => $iconValue ?? '',
            self::OPTION_COLOR => $color ?? 0
        ];
    }

}
