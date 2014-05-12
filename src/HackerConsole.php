<?php

/**
 * Debug_HackerConsole_Main: write debug messages into hidden console.
 * (C) 2005 Dmitry Koterov, http://forum.dklab.ru/users/DmitryKoterov/
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * See http://www.gnu.org/copyleft/lesser.html
 * Console may be toggled using Shift+Ctrl+` (tilde) combination.
 *
 * @version 1.x $Id: Main.php 168 2007-01-30 21:12:03Z dk $
 */
class HackerConsole
{
    private static $_instance;

    private $_hc_height = "400"; // height of the console (pixels)
    private $_hc_entries = array();
    private $tabSize = 4;

    /**
     * @param bool $autoAttach
     * @return HackerConsole
     */
    public static function getInstance($autoAttach = false)
    {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self($autoAttach);
        }

        return self::$_instance;
    }

    /**
     * Create new console. If $autoAttach, output buffering handler is set to automatically attach JavaScript showing code to HTML page.
     * @param bool $autoAttach
     */
    final private function __construct($autoAttach = false)
    {
        if ($autoAttach) {
            ob_start(array(&$this, '_obHandler'));
        }
    }

    final private function __clone()
    {

    }

    /**
     * Attach the console to given HTML page.
     * @param string $page
     * @return string
     */
    public function attachToHtml($page)
    {
        $js = implode("", file(dirname(__FILE__) . '/Js.js'));
        if (get_magic_quotes_runtime()) {
            $js = stripslashes($js);
        }
        $js = str_replace('{HEIGHT}', $this->_hc_height, $js);
        // We MUST use "hackerConsole" instead of "console" because of Safari.
        $code = "window.hackerConsole = window.hackerConsole || window.Debug_HackerConsole_Js && new window.Debug_HackerConsole_Js();\n";
        $code .= "if (window.hackerConsole) setTimeout(function() { with (window.hackerConsole) {\n";
        foreach ($this->_hc_entries as $gid => $elements) {
            foreach ($elements as $e) {
                if ($e['tip'] === null) {
                    $file = str_replace('\\', '/', $e['file']);
                    if (isset($_SERVER['DOCUMENT_ROOT'])) {
                        // Under IIS DOCUMENT_ROOT may not be available.
                        $dr = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
                        $file = preg_replace('{^' . preg_quote($dr, '{}') . '}is', '~', $file);
                    }
                    $title = "at {$file} line {$e['line']}" . (!empty($e['function']) ? ", {$e['function']}" : "");
                } else {
                    $title = $e['tip'];
                }
                $text = $this->toPre($e['text']);
                if (!empty($e['color'])) {
                    $text = "<div style=\"color:" . $e['color'] . "\">$text</div>";
                }
                $code .= "  out(" . $this->_toJs($text) . ", " . $this->_toJs($title) . ", " . $this->_toJs(
                        $gid
                    ) . ");\n";
            }
        }
        $code .= "}}, 200);";
        $html = '';
        // Dirty close opened tags. This is bad, but better than nothing...
        $lower = strtolower($page);
        if (strpos($lower, '</body>') === false) {
            foreach (array('script', 'xmp', 'pre') as $tag) {
                if (substr_count($lower, "<$tag") > substr_count($lower, "</$tag")) {
                    $html .= "</$tag>";
                }
            }
        }
        $html .= "\n";
        $html .= "<!-- ##################### -->\n";
        $html .= "<!-- ### HackerConsole ### -->\n";
        $html .= "<!-- ##################### -->\n";
        $html .= "<script type=\"text/javascript\" language=\"JavaScript\">//<![CDATA[\n" . $js . "\n" . $code . "\n//]]></script>\n";
        $page = preg_replace('{(?=</body[^>]*>|$)}si', preg_replace('/([\\\\$])/', '\\\\$1', $html), $page, 1);

        return $page;
    }


    /**
     * Add new message to the console.
     * Messages may be grouped together using $group parameters for better view.
     * By default messages are tipped with caller context (file, line).
     * Contexts generated by call_user_func() are skipped!
     *
     * @param $msg
     * @param string $group
     * @param null $color
     * @param null $tip
     */
    public function out($msg, $group = "message", $color = null, $tip = null)
    {

        // Detect caller if needed. Used in tip.
        $s = array();
        if ($tip === null) {
            // Find caller. Use call_user_func to get context of out() calling.
            $s = call_user_func(
                array(&$this, 'debug_backtrace_smart'),
                'call_user_func.*', // ignore indirect contexts
                true
            );
        }

        if (is_scalar($msg)) {
            $text = "$msg\n";
        } else {
            $text = HackerConsole::print_r($msg, true);
        }

        $this->_hc_entries[$group][] = array(
            'file' => isset($s['file']) ? $s['file'] : null,
            'line' => isset($s['line']) ? $s['line'] : null,
            'function' => isset($s['function']) ? $s['function'] : null,
            'text' => $text,
            'color' => $color,
            'tip' => $tip,
        );
    }

    /**
     * Format plaintext like <pre> tag does, but with <br> at the line tails
     * and &nbsp; in line prefixes.
     * @param string $text
     * @param null|int $tabSize
     * @return string
     */
    public function toPre($text, $tabSize = null)
    {
        $text = htmlspecialchars($text);
        $text = HackerConsole::expandTabs($text, ($tabSize === null ? $this->tabSize : $tabSize));
        $text = str_replace(' ', '&nbsp;', $text);
        $text = nl2br($text);

        return $text;
    }


    /**
     *  We need manual custom print_r() to use it in OB handlers
     * (original print_r() cannot work inside OB handler).
     * @param $obj
     * @param int $no_print
     * @param int $level
     * @return mixed|null|string
     */
    public function print_r($obj, $no_print = 0, $level = 0)
    {
        if ($level < 10) {
            if (is_array($obj)) {
                $type = "Array[" . count($obj) . "]";
            } elseif (is_object($obj)) {
                $type = "Object";
            } elseif (gettype($obj) == "boolean") {
                $type = $obj ? "TRUE" : "FALSE";
            } elseif ($obj === null) {
                $type = "NULL";
            } else {
                $type = preg_replace("/\r?\n/", "\\n", $obj);
            }
            $buf = $type;
            if (is_array($obj) || is_object($obj)) {
                $leftSp = str_repeat("    ", $level + 1);
                for (reset($obj); list($k, $v) = each($obj);) {
                    if ($k === "GLOBALS") {
                        continue;
                    }
                    $buf .= "\n{$leftSp}[$k] => " . HackerConsole::print_r($v, $no_print, $level + 1);
                }
            }
        } else {
            $buf = "*RECURSION*";
        }
        $buf = str_replace("\x00", " ", $buf); // PHP5 private methods contain \x00 in names
        if ($no_print) {
            return $buf;
        } else {
            echo $buf;
        }

        return null;
    }

    /**
     * Correctly convert tabulators to spaces.
     * @param $text
     * @param int $tabSize
     * @return string
     */
    public function expandTabs($text, $tabSize = 4)
    {
        $GLOBALS['expandTabs_tabSize'] = $tabSize;
        while (1) {
            $old = $text;
            $text = preg_replace_callback(
                '/^([^\t\r\n]*)\t(\t*)/m',
                array('HackerConsole', 'expandTabs_callback'),
                $text
            );
            if ($old === $text) {
                return $text;
            }
        }

        return $text;
    }

    /**
     * @param $m
     * @return string
     */
    public function expandTabs_callback($m)
    {
        $tabSize = $GLOBALS['expandTabs_tabSize'];
        $n =
            intval((strlen($m[1]) + $tabSize) / $tabSize) * $tabSize
            - strlen($m[1])
            + strlen($m[2]) * $tabSize;

        return $m[1] . str_repeat(' ', $n);
    }

    /**
     * @param $s
     * @return string
     */
    private function _obHandler($s)
    {
        return $this->attachToHtml($s);
    }

    /**
     * @param $a
     * @return string
     */
    private function _toJs($a)
    {
        $a = addslashes($a);
        $a = str_replace(array("\n", "\r", ">", "<"), array('\n', '\r', "'+'>", "<'+'"), $a);

        return "'$a'";
    }

    /**
     * Return stacktrace. Correctly work with call_user_func*
     * (totally skip them correcting caller references).
     * If $returnCaller is true, return only first matched caller,
     * not all stacktrace.
     * @param null $ignoresRe
     * @param bool $returnCaller
     * @return array
     * @version 2.03
     */
    private function debug_backtrace_smart($ignoresRe = null, $returnCaller = false)
    {
        if (!is_callable($tracer = 'debug_backtrace')) {
            return array();
        }
        $trace = $tracer();

        if ($ignoresRe !== null) {
            $ignoresRe = "/^(?>{$ignoresRe})$/six";
        }
        $smart = array();
        $framesSeen = 0;
        for ($i = 0, $n = count($trace); $i < $n; $i++) {
            $t = $trace[$i];
            if (!$t) {
                continue;
            }

            // Next frame.
            $next = isset($trace[$i + 1]) ? $trace[$i + 1] : null;

            // Dummy frame before call_user_func* frames.
            if (!isset($t['file'])) {
                $t['over_function'] = $trace[$i + 1]['function'];
                $t = $t + $trace[$i + 1];
                $trace[$i + 1] = null; // skip call_user_func on next iteration
            }

            // Skip myself frame.
            if (++$framesSeen < 2) {
                continue;
            }

            // 'class' and 'function' field of next frame define where
            // this frame function situated. Skip frames for functions
            // situated in ignored places.
            if ($ignoresRe && $next) {
                // Name of function "inside which" frame was generated.
                $frameCaller = (isset($next['class']) ? $next['class'] . '::' : '') . (isset($next['function']) ? $next['function'] : '');
                if (preg_match($ignoresRe, $frameCaller)) {
                    continue;
                }
            }

            // On each iteration we consider ability to add PREVIOUS frame
            // to $smart stack.
            if ($returnCaller) {
                return $t;
            }
            $smart[] = $t;
        }

        return $smart;
    }
}