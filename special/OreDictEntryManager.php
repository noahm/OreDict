<?php
/**
 * OreDictEntryManager special page file
 *
 * @file
 * @ingroup Extensions
 * @version 1.0.1
 * @author Jinbobo <paullee05149745@gmail.com>
 * @license
 */

class OreDictEntryManager extends SpecialPage {
	function __construct(){
		parent::__construct('OreDictEntryManager', 'editoredict');
	}

	function execute($par){
		global $wgUser;
		// Restrict access from unauthorized users
		if (!$this->userCanExecute($this->getUser())){
			$this->displayRestrictionError();
			return;
		}

		$out = $this->getOutput();

		// Add modules
		$out->addModules( 'ext.oredict.manager' );

		$this->setHeaders();
		$this->outputHeader();

		$opts = new FormOptions();

		$opts->add( 'entry_id', 0 );
		$opts->add( 'tag_name', '' );
		$opts->add( 'item_name', '' );
		$opts->add( 'mod_name', '' );
		$opts->add( 'grid_params', '' );
		$opts->add( 'flags', 0 );
		$opts->add( 'orig_flags', 0);
		$opts->add( 'update', 0 );

		$opts->fetchValuesFromRequest( $this->getRequest() );

		// Give precedence to subpage syntax
		if ( isset($par)) {
			$opts->setValue( 'entry_id', $par );
		}

		$out->addHtml($this->outputSearchForm());

		if($opts->getValue('update') == 1 && $opts->getValue('entry_id') == -1){
			$opts->setValue('entry_id', $this->createEntry($opts));
		}
		if($opts->getValue('entry_id') === 0) return;
		if($opts->getValue('update') == 1 && $opts->getValue('entry_id') != -1){
			// XSRF prevention
			if ( !$wgUser->matchEditToken( $this->getRequest()->getVal( 'token' ) ) ) {
				return;
			}

			$this->updateEntry($opts);
		}

		// Load data
		$dbr = wfGetDB(DB_SLAVE);
		$results = $dbr->select('ext_oredict_items','*',array('entry_id' => $opts->getValue('entry_id')));

		if($results->numRows() == 0 && $opts->getValue('entry_id') != -1 && $opts->getValue('entry_id') != -2){
			$out->addWikitext('Query returned an empty set (i.e. zero rows).');
			// $out->addHtml($this->outputUpdateForm());
		} else if($opts->getValue('entry_id') == -2){
			$out->addWikitext('Insert failed!');
			$out->addHtml($this->outputUpdateForm());
		} else if($results->numRows() == 1){
			$out->addHtml($this->outputUpdateForm($results->current()));
		} else {
			$out->addHtml($this->outputUpdateForm());
		}
	}

	private function createEntry(FormOptions $opts){
		$dbw = wfGetDB(DB_MASTER);

		// Check if exists
		if(OreDict::checkExists($opts->getValue('item_name'), $opts->getValue('tag_name'), $opts->getValue('mod_name'))) return -2;

		$dbw->insert('ext_oredict_items', array(
			'tag_name' => $opts->getValue('tag_name'),
			'item_name' => $opts->getValue('item_name'),
			'mod_name' => $opts->getValue('mod_name'),
			'grid_params' => $opts->getValue('grid_params'),
			'flags' => $opts->getValue('flags')
		));

		$tableName = $dbw->tableName('ext_oredict_items');
		$result = $dbw->query("SELECT `entry_id` AS id FROM $tableName ORDER BY `entry_id` DESC LIMIT 1 ");

		$mod = $opts->getValue('mod_name');
		$tag = $opts->getValue('tag_name');
		$item = $opts->getValue('item_name');

		$target = empty($mod) || $mod == "" ? "$tag - $item" : "$tag - $item ($mod)";
		// Start log
		$logEntry = new ManualLogEntry('oredict', 'createentry');
		$logEntry->setPerformer($this->getUser());
		$logEntry->setTarget(Title::newFromText("Entry/$target", NS_SPECIAL));
		$logEntry->setParameters(array("4::id" => $result->current()->id, "5::tag" => $opts->getValue('tag_name'), "6::item" => $opts->getValue('item_name'), "7::mod" => $opts->getValue('mod_name'), "8::params" => $opts->getValue('grid_params'), "9::flags" => sprintf("0x%03X (0b%09b)",$opts->getValue('flags'),$opts->getValue('flags'))));
		$logId = $logEntry->insert();
		$logEntry->publish($logId);
		// End log

		return intval($result->current()->id);
	}

	private function updateEntry(FormOptions $opts){
		$dbw = wfGetDB(DB_MASTER);
		$stuff = $dbw->select('ext_oredict_items', '*', array('entry_id' => $opts->getValue('entry_id')));
		$result = $dbw->update('ext_oredict_items', array(
			//'tag_name' => $opts->getValue('tag_name'),
			'item_name' => $opts->getValue('item_name'),
			'mod_name' => $opts->getValue('mod_name'),
			'grid_params' => $opts->getValue('grid_params'),
			'flags' => $opts->getValue('flags')
		), array('entry_id' => $opts->getValue('entry_id')));

		if($stuff->numRows() == 0) return;
		if($result == false) return;

		$tag = $opts->getValue('tag_name');
		$fItem = $opts->getValue('item_name');
		$mod = $opts->getValue('mod_name');
		$params = $opts->getValue('grid_params');
		$flags = $opts->getValue('flags');
		$item = $stuff->current();

		// Prepare log vars
		$target = empty($mod) || $mod == "" ? "$tag - $fItem" : "$tag - $fItem ($mod)";

		$diff = array();
		if($item->item_name != $fItem){
			$diff['item'][] = $item->item_name;
			$diff['item'][] = $fItem;
		}
		if($item->mod_name != $mod){
			$diff['mod'][] = $item->mod_name;
			$diff['mod'][] = $mod;
		}
		if($item->grid_params != $params){
			$diff['params'][] = $item->grid_params;
			$diff['params'][] = $params;
		}
		if($item->flags != $flags){
			$diff['flags'][] = sprintf("0x%03X (0b%09b)",$item->flags,$item->flags);
			$diff['flags'][] = sprintf("0x%03X (0b%09b)",$flags,$flags);
		}
		$diffString = "";
		foreach($diff as $field => $change){
			$diffString .= "$field [$change[0] -> $change[1]] ";
		}
		if($diffString == "" || count($diff) == 0) return; // No change

		// Start log
		$logEntry = new ManualLogEntry('oredict', 'editentry');
		$logEntry->setPerformer($this->getUser());
		$logEntry->setTarget(Title::newFromText("Entry/$target", NS_SPECIAL));
		$logEntry->setParameters(array("6::tag" => $tag, "7::item" => $item->item_name, "8::mod" => $item->mod_name, "9::params" => $item->grid_params, "10::flags" => sprintf("0x%03X (0b%09b)",$item->flags,$item->flags), "11::to_item" => $fItem, "12::to_mod" => $mod, "13::to_params" => $params, "14::to_flags" => sprintf("0x%03X (0b%09b)",$flags,$flags),"15::id" => $item->entry_id, "4::diff" => $diffString, "5::diff_json" => json_encode($diff)));
		$logId = $logEntry->insert();
		$logEntry->publish($logId);
		// End log

		$toggleFlag = 0xc0 & (intval($opts->getValue('orig_flags')) ^ intval($opts->getValue('flags')));
		if($toggleFlag){
			$tableName = $dbw->tableName('ext_oredict_items');
			$tagName = $dbw->addQuotes($opts->getValue('tag_name'));
			$entryId = intval($opts->getValue('entry_id'));
			$dbw->query("UPDATE $tableName SET `flags` = `flags` ^ $toggleFlag WHERE `tag_name` = $tagName AND `entry_id` != $entryId");

			// Start log
			$logEntry = new ManualLogEntry('oredict', 'edittag');
			$logEntry->setPerformer($this->getUser());
			$logEntry->setTarget(Title::newFromText("Tag/{$opts->getValue('tag_name')}", NS_SPECIAL));
			$logEntry->setParameters(array("4::tag" => $tag, "5::toggle" => sprintf("0x%03X (0b%09b)",$toggleFlag,$toggleFlag)));
			$logId = $logEntry->insert();
			$logEntry->publish($logId);
			// End log
		}
	}

	private function outputUpdateForm(stdClass $opts = NULL){
		global $wgScript, $wgUser;
		$vEntryId = is_object($opts) ? $opts->entry_id : -1;
		$vTagName = is_object($opts) ? $opts->tag_name : '';
		$vTagReadonly = is_object($opts) ? "readonly=\"readonly\"" : '';
		$vItemName = is_object($opts) ? $opts->item_name : '';
		$vModName = is_object($opts) ? $opts->mod_name : '';
		$vGridParams = is_object($opts) ? $opts->grid_params : '';
		$vFlags = is_object($opts) ? $opts->flags : 0xcf;
		$msgFieldsetMain = is_object($opts) ? wfMessage('oredict-manager-edit-legend') : wfMessage('oredict-manager-create-legend');
		$msgFieldsetEntryFlags = wfMessage('oredict-manager-entry-flags-legend');
		$msgFieldsetTagFlags = wfMessage('oredict-manager-tag-flags-legend');
		$msgFieldsetSpecialFlags = wfMessage('oredict-manager-special-flags-legend');
		$msgSubmitValue = is_object($opts) ? wfMessage('oredict-manager-update') : wfMessage('oredict-manager-create');
		$form = "<table>";
		$form .= OreDictForm::createFormRow('manager', 'entry_id', $vEntryId, "text", "readonly=\"readonly\"");
		$form .= OreDictForm::createFormRow('manager', 'tag_name', $vTagName, "text", $vTagReadonly);
		$form .= OreDictForm::createFormRow('manager', 'item_name', $vItemName);
		$form .= OreDictForm::createFormRow('manager', 'mod_name', $vModName);
		$form .= OreDictForm::createFormRow('manager', 'grid_params', $vGridParams);
		$form .= OreDictForm::createFormRow('manager', 'flags', $vFlags, "text", "readonly=\"readonly\"");
		$form .= "</table>";
		$form .= Xml::fieldset($msgFieldsetTagFlags);
		$form .= "<table>";
		$form .= OreDictForm::createCheckBox('manager', 'tag_call_grid', 0x40, $vFlags & 0x40);
		$form .= OreDictForm::createCheckBox('manager', 'tag_call_tag', 0x80, $vFlags & 0x80);
		$form .= "</table>";
		$form .= Xml::closeElement('fieldset');
		$form .= Xml::fieldset($msgFieldsetEntryFlags);
		$form .= "<table>";
		$form .= OreDictForm::createCheckBox('manager', 'call_grid', 0x01, $vFlags & 0x01);
		$form .= OreDictForm::createCheckBox('manager', 'call_tag', 0x02, $vFlags & 0x02);
		$form .= OreDictForm::createCheckBox('manager', 'disp_grid', 0x04, $vFlags & 0x04);
		$form .= OreDictForm::createCheckBox('manager', 'disp_tag', 0x08, $vFlags & 0x08);
		$form .= "</table>";
		$form .= Xml::closeElement('fieldset');
		$form .= Xml::fieldset($msgFieldsetSpecialFlags);
		$form .= "<table>";
		$form .= OreDictForm::createCheckBox('manager', 'entry_del', 0x100, $vFlags & 0x100, "", "style=\"color:red; font-weight:bold;\"");
		$form .= "</table>";
		$form .= Xml::closeElement('fieldset');
		$form .= "<input type=\"submit\" value=\"".$msgSubmitValue."\">";

		$out = Xml::openElement('form', array('method' => 'get', 'action' => $wgScript, 'id' => 'ext-oredict-manager-form')) .
			Xml::fieldset($msgFieldsetMain) .
			Html::hidden('title', $this->getTitle()->getPrefixedText()) .
			Html::hidden('token', $wgUser->getEditToken()) .
			Html::hidden('update', 1) .
			Html::hidden('orig_flags', $vFlags) .
			$form .
			Xml::closeElement( 'fieldset' ) . Xml::closeElement( 'form' ) . "\n";

		return $out;
	}

	private function outputSearchForm(){
		global $wgScript;
		$form = "<table>";
		$form .= OreDictForm::createFormRow('manager-filter', 'entry_id', '', 'number', 'min="1" id="form-entry-id"');
		$form .= "<tr><td></td><td><input type=\"submit\" value=\"".wfMessage("oredict-manager-submit")."\"><input type=\"button\" value=\"Create new entry\" id=\"form-create-new\"></td></tr>";
		$form .= "</table>";

		$out = Xml::openElement('form', array('method' => 'get', 'action' => $wgScript, 'id' => 'ext-oredict-manager-filter')) .
			Xml::fieldset($this->msg('oredict-manager-filter-legend')->text()) .
			Html::hidden('title', $this->getTitle()->getPrefixedText()) .
			$form .
			Xml::closeElement( 'fieldset' ) . Xml::closeElement( 'form' ) . "\n";

		return $out;
	}
}
