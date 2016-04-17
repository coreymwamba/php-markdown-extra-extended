<?php
require_once('markdown.php');
define( 'MARKDOWNEXTRAEXTENDED_VERSION',  "0.3" );

function MarkdownExtended($text, $default_claases = array()){
  $parser = new MarkdownExtraExtended_Parser($default_claases);
  return $parser->transform($text);
}

class MarkdownExtraExtended_Parser extends MarkdownExtra_Parser {
	# Tags that are always treated as block tags:
	var $block_tags_re = 'figure|figcaption|p|div|h[1-6]|blockquote|pre|table|dl|ol|ul|address|form|fieldset|iframe|hr|legend';
	var $default_classes;
		
	function MarkdownExtraExtended_Parser($default_classes = array()) {
	    $default_classes = $default_classes;
		
		$this->block_gamut += array(
			"doFencedFigures" => 7,
		);
		$this->span_gamut += array(
			"doQuotes" => -50,
			"doStrikethroughs" => -35
		);
		parent::MarkdownExtra_Parser();
	}
	
	function transform($text) {	
		$text = parent::transform($text);				
		return $text;		
	}
	
	function doHardBreaks($text) {
		# Do hard breaks:
		# EXTENDED: changed to allow breaks without two spaces and just one new line
		# original code /* return preg_replace_callback('/ {2,}\n/', */
		return preg_replace_callback('/ *\n/', 
			array(&$this, '_doHardBreaks_callback'), $text);
	}



	function doBlockQuotes($text) {
		$text = preg_replace_callback('/
			(?>^[ ]*>[ ]?
				(?:\{(.+?)\})?
				[ ]*(.+\n(?:.+\n)*)
			)+	
			/xm',
			array(&$this, '_doBlockQuotes_callback'), $text);

		return $text;
	}
# >{link|title} text will give <blockquote title="title" cite="link">text</blockquote>	
	function _doBlockQuotes_callback($matches) {
		$cite_title_array = explode('|',$matches[1],2);
		$cite = $cite_title_array[0];
		$bq_title = $cite_title_array[1];
		$bq = '> ' . $matches[2];
		# trim one level of quoting - trim whitespace-only lines
		$bq = preg_replace('/^[ ]*>[ ]?|^[ ]+$/m', '', $bq);
		$bq = $this->runBlockGamut($bq);		# recurse

		$bq = preg_replace('/^/m', "  ", $bq);
		# These leading spaces cause problem with <pre> content, 
		# so we need to fix that:
		$bq = preg_replace_callback('{(\s*<pre>.+?</pre>)}sx', 
			array(&$this, '_doBlockQuotes_callback2'), $bq);
		
		$res = "<blockquote";
		$res .= empty($bq_title) ? "" : " title=\"$bq_title\"";
		$res .= empty($cite) ? ">" : " cite=\"$cite\">";
		$res .= "\n$bq\n</blockquote>";
		return "\n". $this->hashBlock($res)."\n\n";
	}

	function _doBlockQuotes_callback2($matches) {
		$pre = $matches[1];
		$pre = preg_replace('/^  /m', '', $pre);
		return $pre;
	}

	function doFencedCodeBlocks($text) {
		$less_than_tab = $this->tab_width;
		
		$text = preg_replace_callback('{
				(?:\n|\A)
				# 1: Opening marker
				(
					~{3,}|`{3,} # Marker: three tilde or more.
				)
				
				[ ]?(\w+)?(?:,[ ]?(\d+))?[ ]* \n # Whitespace and newline following marker.
				
				# 3: Content
				(
					(?>
						(?!\1 [ ]* \n)	# Not a closing marker.
						.*\n+
					)+
				)
				
				# Closing marker.
				\1 [ ]* \n
			}xm',
			array(&$this, '_doFencedCodeBlocks_callback'), $text);

		return $text;
	}
	
	function _doFencedCodeBlocks_callback($matches) {
		$codeblock = $matches[4];
		$codeblock = htmlspecialchars($codeblock, ENT_NOQUOTES);
		$codeblock = preg_replace_callback('/^\n+/',
			array(&$this, '_doFencedCodeBlocks_newlines'), $codeblock);
		//$codeblock = "<pre><code>$codeblock</code></pre>";
		//$cb = "<pre><code";
		$cb = empty($matches[3]) ? "<pre><code" : "<pre class=\"linenums:$matches[3]\"><code"; 
		$cb .= empty($matches[2]) ? ">" : " class=\"language-$matches[2]\">";
		$cb .= "$codeblock</code></pre>";
		return "\n\n".$this->hashBlock($cb)."\n\n";
	}

	function doFencedFigures($text){
		$text = preg_replace_callback('{
			(?:\n|\A)
			# 1: Opening marker
			(
				={3,} # Marker: equal sign.
			)
			
			[ ]?(?:\[([^\]]+)\])?[ ]* \n # Whitespace and newline following marker.
			
			# 3: Content
			(
				(?>
					(?!\1 [ ]?(?:\[([^\]]+)\])?[ ]* \n)	# Not a closing marker.
					.*\n+
				)+
			)
			
			# Closing marker.
			\1 [ ]?(?:\[([^\]]+)\])?[ ]* \n
		}xm', array(&$this, '_doFencedFigures_callback'), $text);		
		
		return $text;	
	}
	
	function _doFencedFigures_callback($matches) {
		# get figcaption
		$topcaption = empty($matches[2]) ? null : $this->runBlockGamut($matches[2]);
		$bottomcaption = empty($matches[5]) ? null : $this->runBlockGamut($matches[5]);
		$figure = $matches[3];
		$figure = $this->runBlockGamut($figure); # recurse

		$figure = preg_replace('/^/m', "  ", $figure);
		# These leading spaces cause problem with <pre> content, 
		# so we need to fix that - reuse blockqoute code to handle this:
		$figure = preg_replace_callback('{(\s*<pre>.+?</pre>)}sx', 
			array(&$this, '_doBlockQuotes_callback2'), $figure);
		
		$res = "<figure>";
		if(!empty($topcaption)){
			$res .= "\n<figcaption>$topcaption</figcaption>";
		}
		$res .= "\n$figure\n";
		if(!empty($bottomcaption) && empty($topcaption)){
			$res .= "<figcaption>$bottomcaption</figcaption>";
		}
		$res .= "</figure>";		
		return "\n". $this->hashBlock($res)."\n\n";
	}
	function doStrikethroughs($text) {
	#
	# Replace ~~some deleted text~~ with <del>some deleted text</del>
	#
		$text = preg_replace_callback('{
				~~([^~]+)~~
			}xm',
			array(&$this, '_doStrikethroughs_callback'), $text);
		return $text;
	}
	function _doStrikethroughs_callback($matches) {
		$res = "<del>" . $matches[1] . "</del>";
		return $this->hashBlock($res);
	}
	function doQuotes($text) {
	#
	# Replace %%{link|title} some quotation%% with <q title="title" cite="link">some quotation</q>
	#
		$text = preg_replace_callback('{
				%%(?:\{(.+?)\})?([^%%]+)%%
			}xm',
			array(&$this, '_doQuotes_callback'), $text);
		return $text;
	}
	function _doQuotes_callback($matches) {
                $qct = explode('|',$matches[1],2);
                $cite = $qct[0];
                $qtitle = $qct[1];
		$res = "<q";
		$res .= empty($qtitle) ? "" : " title=\"$qtitle\"";
                $res .=  empty($cite) ? ">" : " cite=\"$cite\">";
                $res .= $matches[2] . "</q>";
		return $this->hashBlock($res);
	}
	
	
}
?>
