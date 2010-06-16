<?


//function get file extension
function getFileExtension($str) {
	$i = strrpos($str,".");
	 if (!$i) { return ""; }
	
	$l = strlen($str) - $i;
	$ext = substr($str,$i+1,$l);
	
	return $ext;
}


//makes sure only regular charactesr are in the string
		function cleanString( $string = "" ) {
	
	$allowedCharacters= array( " ", "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", 
								"k", "l", "m", "n", "o", "p", "q", "r", "s", 
								"t", "u", "v", "w", "x", "y", "z",
								"1", "2", "3", "4", "5", "6", "7", "8", "9", "0",
								"‘",   "’",   "‚",   "“",      
"”",      
"„",      
"†",      
"‡",      
"‰",      
"‹",      
"›",      
"?",      
"?",      
"?",      
"?",      
"?",      
"?",      
"?",      
"?",      
"?",      
"?",      
"?",      
"™",      
"!",   	
"\"",   	
"#",   	
"$",   	
"%",   
"&",   	
"'",   	
"(",   	
")",   	
"*",   	
"+",   	
",",   	
"-",   	
".",  



"/",   	

":",   	
";",   	
"<",   	
"=",   	
">",   	
"?",   	
"@",   	

"[",   	
"\\",   	
"]",   	
"^",   
"_",   	
"`",   	
"{",   	
"|",   	
"}",   	
"~",   	
"–",   	
"—",  
"¡",   
"¢",   	
"£",   	
"?",   	
"¥",   	
"?",   	
"§",   
"¨",   	
"©",   	
"ª",   
"«",   	
"¬",   	
"?",   
"®",   
"¯",   
"°",  
"±",
"?",  
"?",   
"´",   	
"µ",   	
"¶",   	
"·",   	
"¸",   	
"?",  
"º",   
"»",   
"?",   	
"?",   	
"?",   	
"¿",   
"À",   
"Á",   	
"Â",   	
"Ã",
"Ä",
"Å",
"Æ",
"Ç",
"È",
"É",
"Ê",
"Ë",
"Ì",
"Í",
"Î",
"Ï",
"?",
"Ñ",
"Ò",
"Ó",
"Ô",
"Õ",


	
"Ö",
"?",
"Ø",
"Ù",
"Ú",
"Û",
"Ü",
"?",
"?",
"ß",
"à",
"á",
"â",
"ã",
"ä",
"å",
"æ",
"ç",
"è",


"é",
"ê",
"ë",
"ì",
"í",
"î",
"ï",
"?",
"ñ",
"ò",
"ó",
"ô",
"õ",
"ö",
"÷",   	
"ø",   
"ù",  
"ú",   	
"û",   	
"ü",  
"?",   
"?",   
"ÿ" );
	$newString .= "";
	
	for( $incString = 0; $incString < strlen( $string ); $incString++ ) {
		
		if( in_array( strtolower( $string[$incString] ), $allowedCharacters ) ) {
			
			$newString .= $string[$incString];
		}
	}
	return $newString;
}

//this is like SUBSTR, but will cacluate for WHOLE CHARACTERS only, IE: it won't cut a word in half
function cutText($string, $length) {
		    while ($string{$length} != " ") {
		        $length--;
		    }
		    return substr($string, 0, $length);
		}
		