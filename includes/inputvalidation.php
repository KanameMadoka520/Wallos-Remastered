<?php

function validate($value)
{
    $value = trim($value);
    $value = stripslashes($value);
    $value = htmlspecialchars($value);
    return $value;
}

/**
 * Like validate(), but for fields rendered through render_notes_markdown()
 * (includes/markdown.php) rather than echoed directly: HTML-escaping happens
 * at render time there, so it must not also happen at storage time here.
 *
 * @param string $value
 * @return string
 */
function validate_markdown($value)
{
    $value = trim($value);
    $value = stripslashes($value);
    return $value;
}

/**
 * Reverse the escaping applied by validate() for values that are still stored
 * escaped. Render paths that HTML-escape at output time must decode once
 * first, otherwise characters like & show up as &amp;amp;.
 *
 * @param string $value
 * @return string
 */
function wallos_decode_stored_text($value)
{
    return htmlspecialchars_decode((string) $value, ENT_QUOTES);
}

?>
