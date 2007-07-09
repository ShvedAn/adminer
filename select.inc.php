<?php
page_header(lang('Select') . ": " . htmlspecialchars($_GET["select"]));
$fields = fields($_GET["select"]);
$rights = array();
$columns = array();
foreach ($fields as $key => $field) {
	if (isset($field["privileges"]["select"])) {
		$columns[] = $key;
	}
	$rights += $field["privileges"];
}

if (isset($rights["insert"])) {
	echo '<p><a href="' . htmlspecialchars($SELF) . 'edit=' . urlencode($_GET['select']) . '">' . lang('New item') . "</a></p>\n";
}

if (!$columns) {
	echo "<p class='error'>" . lang('Unable to select the table') . ($fields ? "" : ": " . mysql_error()) . ".</p>\n";
} else {
	$indexes = indexes($_GET["select"]);
	echo "<form action='' id='form'>\n<fieldset><legend>" . lang('Search') . "</legend>\n";
	if (strlen($_GET["server"])) {
		echo '<input type="hidden" name="server" value="' . htmlspecialchars($_GET["server"]) . '" />';
	}
	echo '<input type="hidden" name="db" value="' . htmlspecialchars($_GET["db"]) . '" />';
	echo '<input type="hidden" name="select" value="' . htmlspecialchars($_GET["select"]) . '" />';
	echo "\n";
	
	$where = array();
	foreach ($indexes as $i => $index) {
		if ($index["type"] == "FULLTEXT") {
			if (strlen($_GET["fulltext"][$i])) {
				$where[] = "MATCH (" . implode(", ", array_map('idf_escape', $index["columns"])) . ") AGAINST ('" . mysql_real_escape_string($_GET["fulltext"][$i]) . "'" . (isset($_GET["boolean"][$i]) ? " IN BOOLEAN MODE" : "") . ")";
			}
			echo "(<i>" . implode("</i>, <i>", $index["columns"]) . "</i>) AGAINST";
			echo ' <input name="fulltext[' . $i . ']" value="' . htmlspecialchars($_GET["fulltext"][$i]) . '" />';
			echo "<input type='checkbox' name='boolean[$i]' value='1' id='boolean-$i'" . (isset($_GET["boolean"][$i]) ? " checked='checked'" : "") . " /><label for='boolean-$i'>" . lang('BOOL') . "</label>";
			echo "<br />\n";
		}
	}
	$operators = array("=", "<", ">", "<=", ">=", "!=", "LIKE", "REGEXP", "IS NULL");
	$i = 0;
	foreach ((array) $_GET["where"] as $val) {
		if (strlen($val["col"]) && in_array($val["op"], $operators)) {
			$where[] = idf_escape($val["col"]) . " $val[op]" . ($val["op"] != "IS NULL" ? " '" . mysql_real_escape_string($val["val"]) . "'" : "");
			echo "<div><select name='where[$i][col]'><option></option>" . optionlist($columns, $val["col"], "not_vals") . "</select>";
			echo "<select name='where[$i][op]' onchange=\"where_change(this);\">" . optionlist($operators, $val["op"], "not_vals") . "</select>";
			echo "<input name='where[$i][val]' value=\"" . htmlspecialchars($val["val"]) . "\" /></div>\n";
			$i++;
		}
	}
	?>
<script type="text/javascript">
function where_change(op) {
	op.form[op.name.substr(0, op.name.length - 4) + '[val]'].style.display = (op.value == 'IS NULL' ? 'none' : '');
}
<?php if ($i) { ?>
for (var i=0; <?php echo $i; ?> > i; i++) {
	document.getElementById('form')['where[' + i + '][op]'].onchange();
}
<?php } ?>
</script>
<?php
	echo "<div><select name='where[$i][col]'><option></option>" . optionlist($columns, array(), "not_vals") . "</select>";
	echo "<select name='where[$i][op]' onchange=\"where_change(this);\">" . optionlist($operators, array(), "not_vals") . "</select>";
	echo "<input name='where[$i][val]' /></div>\n"; //! JavaScript for adding next
	echo "</fieldset>\n";
	
	echo "<fieldset><legend>" . lang('Sort') . "</legend>\n";
	$order = array();
	$i = 0;
	foreach ((array) $_GET["order"] as $key => $val) {
		if (in_array($val, $columns, true)) {
			$order[] = idf_escape($val) . (isset($_GET["desc"][$key]) ? " DESC" : "");
			echo "<div><select name='order[$i]'><option></option>" . optionlist($columns, $val, "not_vals") . "</select>";
			echo "<input type='checkbox' name='desc[$i]' value='1' id='desc-$i'" . (isset($_GET["desc"][$key]) ? " checked='checked'" : "") . " /><label for='desc-$i'>" . lang('DESC') . "</label></div>\n";
			$i++;
		}
	}
	echo "<div><select name='order[$i]'><option></option>" . optionlist($columns, array(), "not_vals") . "</select>";
	echo "<input type='checkbox' name='desc[$i]' value='1' id='desc-$i' /><label for='desc-$i'>" . lang('DESC') . "</label></div>\n";
	echo "</fieldset>\n";
	
	echo "<fieldset><legend>" . lang('Limit') . "</legend>\n";
	$limit = (isset($_GET["limit"]) ? $_GET["limit"] : "30");
	echo '<div><input name="limit" size="3" value="' . htmlspecialchars($limit) . '" /></div>';
	echo "</fieldset>\n";
	
	echo "<fieldset><legend>" . lang('Action') . "</legend><div><input type='submit' value='" . lang('Select') . "' /></div></fieldset>\n";
	echo "</form>\n";
	echo "<div style='clear: left; margin-bottom: 1em;'></div>\n";
	
	$result = mysql_query("SELECT SQL_CALC_FOUND_ROWS " . implode(", ", array_map('idf_escape', $columns)) . " FROM " . idf_escape($_GET["select"]) . ($where ? " WHERE " . implode(" AND ", $where) : "") . ($order ? " ORDER BY " . implode(", ", $order) : "") . (strlen($limit) ? " LIMIT " . intval($limit) . " OFFSET " . ($limit * $_GET["page"]) : ""));
	if (!mysql_num_rows($result)) {
		echo "<p class='message'>" . lang('No rows.') . "</p>\n";
	} else {
		$found_rows = mysql_result(mysql_query(" SELECT FOUND_ROWS()"), 0); // space for mysql.trace_mode
		$foreign_keys = array();
		foreach (foreign_keys($_GET["select"]) as $foreign_key) {
			foreach ($foreign_key[2] as $val) {
				$foreign_keys[$val][] = $foreign_key;
			}
		}
		$childs = array();
		if (mysql_get_server_info() >= 5) {
			// would be possible in earlier versions too, but only by examining all tables (in all databases)
			$result1 = mysql_query("SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = '" . mysql_real_escape_string($_GET["db"]) . "' AND REFERENCED_TABLE_NAME = '" . mysql_real_escape_string($_GET["select"]) . "' ORDER BY ORDINAL_POSITION");
			while ($row1 = mysql_fetch_assoc($result1)) {
				$childs[$row1["CONSTRAINT_NAME"]][0] = $row1["TABLE_SCHEMA"];
				$childs[$row1["CONSTRAINT_NAME"]][1] = $row1["TABLE_NAME"];
				$childs[$row1["CONSTRAINT_NAME"]][2][] = $row1["REFERENCED_COLUMN_NAME"];
				$childs[$row1["CONSTRAINT_NAME"]][3][] = $row1["COLUMN_NAME"];
			}
			mysql_free_result($result1);
		}
		
		echo "<table border='1' cellspacing='0' cellpadding='2'>\n";
		for ($j=0; $row = mysql_fetch_assoc($result); $j++) {
			if (!$j) {
				echo "<thead><tr><th>" . implode("</th><th>", array_map('htmlspecialchars', array_keys($row))) . "</th><th>" . lang('Action') . "</th></tr></thead>\n";
			}
			echo "<tr>";
			$unique_idf = '&amp;' . implode('&amp;', unique_idf($row, $indexes));
			foreach ($row as $key => $val) {
				if (!isset($val)) {
					$val = "<i>NULL</i>";
				} elseif (preg_match('~blob|binary~', $fields[$key]["type"]) && preg_match('~[\\x80-\\xFF]~', $val)) {
					$val = '<a href="' . htmlspecialchars($SELF) . 'download=' . urlencode($_GET["select"]) . '&amp;field=' . urlencode($key) . $unique_idf . '">' . lang('%d byte(s)', strlen($val)) . '</a>';
				} else {
					$val = (strlen(trim($val)) ? nl2br(htmlspecialchars($val)) : "&nbsp;");
					foreach ((array) $foreign_keys[$key] as $foreign_key) {
						if (count($foreign_keys[$key]) == 1 || count($foreign_key[2]) == 1) {
							$val = '">' . "$val</a>";
							foreach ($foreign_key[2] as $i => $source) {
								$val = "&amp;where%5B$i%5D%5Bcol%5D=" . urlencode($foreign_key[3][$i]) . "&amp;where%5B$i%5D%5Bop%5D=%3D&amp;where%5B$i%5D%5Bval%5D=" . urlencode($row[$source]) . $val;
							}
							$val = '<a href="' . htmlspecialchars(strlen($foreign_key[0]) ? preg_replace('~([?&]db=)[^&]+~', '\\1' . urlencode($foreign_key[0]), $SELF) : $SELF) . 'select=' . htmlspecialchars($foreign_key[1]) . $val; // InnoDB support non-UNIQUE keys
							break;
						}
					}
				}
				echo "<td>$val</td>";
			}
			echo '<td><a href="' . htmlspecialchars($SELF) . 'edit=' . urlencode($_GET['select']) . $unique_idf . '">' . lang('edit') . '</a>';
			foreach ($childs as $child) {
				echo ' <a href="' . htmlspecialchars(strlen($child[0]) ? preg_replace('~([?&]db=)[^&]+~', '\\1' . urlencode($child[0]), $SELF) : $SELF) . 'select=' . urlencode($child[1]);
				foreach ($child[2] as $i => $source) {
					echo "&amp;where[$i][col]=" . urlencode($child[3][$i]) . "&amp;where[$i][op]=%3D&amp;where[$i][val]=" . urlencode($row[$source]);
				}
				echo '">' . htmlspecialchars($child[1]) . '</a>';
			}
			echo '</td>';
			echo "</tr>\n";
		}
		echo "</table>\n";
		if (intval($limit) && $found_rows > $limit) {
			echo "<p>" . lang('Page') . ":\n";
			for ($i=0; $i < $found_rows / $limit; $i++) {
				echo ($i == $_GET["page"] ? $i + 1 : '<a href="' . htmlspecialchars(preg_replace('~(\\?)page=[^&]*&|&page=[^&]*~', '\\1', $_SERVER["REQUEST_URI"]) . ($i ? "&page=$i" : "")) . '">' . ($i + 1) . "</a>") . "\n";
			}
			echo "</p>\n";
		}
	}
	mysql_free_result($result);
}
