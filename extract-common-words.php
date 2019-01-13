<?php
set_time_limit(0);
error_reporting(E_ALL);

// Load environment variables
require __DIR__ . '/vendor/autoload.php';
$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

// Create a new MySQL database connection
if (!$con = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_NAME'))) {
    die('An error occurred while connecting to the MySQL server!' . $con->connect_error);
}

function crawl_page($url)
{
    static $seen = [];
    // Check if the URL has been already crawled
    if ( isset($seen[$url]) || count($seen) == getenv('MAX_URL_CRAWL') ) {
        return;
    }
    $seen[$url] = true;
    echo "Crawling " . $url."\n";
    
    global $con;
    $hrefs = [];

    // cURL request
    $ch = curl_init($url);
    curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, 1);
	$response = curl_exec($ch);
    $curl_info = curl_getinfo( $ch );
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $html = substr($response, $header_size);
    curl_close($ch);

    // Ignore all content-type except text/html
    if( substr( $curl_info['content_type'], 0, 10 ) !== "text/html;" )
    {
        return;
    }
	
	$dom = new DOMDocument();
    @$dom->loadHTML($html);

    // Remove script tags
    while (($r = $dom->getElementsByTagName("script")) && $r->length) {
        $r->item(0)->parentNode->removeChild($r->item(0));
    }
    $html = $dom->saveHTML();

    // Get all href links
    $tags = $dom->getElementsByTagName('a');
    foreach ($tags as $tag) {
        $href = pretty_url( $tag->getAttribute('href') );
        if( $href != '' && !is_external_url($href) && !in_array($href, $hrefs) ) {
            $hrefs[] =  $href;
        }
    }
    
    // Convert HTML to plain text
    $plainText = $dom->textContent;
    $plainText = clean( $plainText );
    $words = explode( ' ', $plainText );
    $excluded_words = explode( ',', getenv('EXCLUDE_WORDS') );
    $words = array_filter($words, function($value) use ($excluded_words) {
        if( $value != '' && !in_array( $value, $excluded_words ))
        {
            return trim( $value, '-' );
        }
    });
    $words = array_count_values( $words );

    foreach($words as $k => $v)
    {
        $data[] = "('" . $con->real_escape_string($k) . "',". $v . ")";
    }
    
    // Insert/update the word count
	if(!empty($data))
	{
		$sql = "INSERT INTO word_count (word, word_count) VALUES " . implode(',', $data) . " ON DUPLICATE KEY UPDATE word_count = word_count + VALUES(word_count)";
        if ($con->query($sql) === FALSE)
        {
            echo "Error: " . $sql . "\n" . $con->error;
        }
    }

    // If additional links are present, crawl them
    if( !empty($hrefs) )
    {
        foreach ($hrefs as $key => $href) {
            crawl_page( $href );
        }
    }
}

// Function to generate a valid URL for cURL
function pretty_url( $url = '' )
{
    $url = preg_replace('/\?.*/', '', $url);
    $url = preg_replace('/\#.*/', '', $url);
    $url = rtrim( trim( $url ), '/' );

    if( $url == '' || $url == '#' || $url == '/' )
    {
        $url = '';
    }
    else if( substr( $url, 0, 1 ) === "/" )
    {
        $url = getenv('URL').$url;
    }
    else if( substr( $url, 0, 7 ) === "mailto:" )
    {
        $url = '';
    }
    else if( substr( $url, 0, 4 ) !== "http" )
    {
        $url = getenv('URL').$url;
    }

    $url = preg_replace("/^http:/i", "https:", $url); // Convert all http:// links to https://

    return $url;
}

// Function to check if an URL is an external URL
function is_external_url($url)
{
    $main_url = parse_url( getenv('URL') );
    $url_to_check = parse_url( $url );
    if( $main_url['host'] != $url_to_check['host'] )
    {
        return true;
    }

    return false;
}

// Function to clean a string
function clean( $string )
{
    $string = str_replace('&nbsp;', ' ', $string); // Replace &nbsp; with space
    $string = preg_replace('/[^A-Za-z\-\s\']/', '', $string); // Removes special chars.
    $string = preg_replace('~[\s]+~', ' ', $string); // Remove extra spaces
    $string = trim( $string );

    return strtolower( $string );
}

// Crawl the main page
crawl_page( getenv('URL') );

// Get the top commmon words
$sql = "SELECT word, word_count FROM word_count ORDER BY word_count DESC LIMIT " . getenv('LIMIT_TOP_COMMON_WORDS');
if ( $result = $con->query($sql) )
{
    echo "Word | Count\n";
    while( $obj = $result->fetch_object() )
    { 
        echo $obj->word . " | " . $obj->word_count . "\n";
    }
}
else
{
    echo "Error: " . $sql . "\n" . $con->error;
}

// Close the mysql connection
$con->close();