<?php

use CRM_Googlegroups_Utils as GG;

/**
 * @file
 * This provides the Sync Push from CiviCRM to Google Groups.
 */

class CRM_Googlegroups_Form_Sync extends CRM_Core_Form {

  const QUEUE_NAME = 'gg-sync';
  const END_URL    = 'civicrm/googlegroups/sync';
  const END_PARAMS = 'state=done';
  const BATCH_COUNT = 10;

  const EMAIL_PARAMS = array(
                'from' => "fixme@example.com",
                'toName' => "FIXME",
                'toEmail' => "fixme@example.com",
                'subject' => "CiviCRM GG-Syncing for %s",
		'text' => "%s\n\n%s\n\nAdminister at: https://admin.google.com/ac/groups/%s"
        );

  /**
   * Function to pre processing
   *
   * @return None
   * @access public
   */
  function preProcess() {
    $state = CRM_Utils_Request::retrieve('state', 'String', CRM_Core_DAO::$_nullObject, FALSE, 'tmp', 'GET');
    if ($state == 'done') {
      $stats  = GG::getStats();
      $groups = GG::getGroupsToSync(array(), null);
      if (!$groups) {
        return;
      }
      $output_stats = array();
      foreach ($groups as $group_id => $details) {
        $group_stats = $stats[$details['group_id']];
        $output_stats[] = array(
          'name' => $details['civigroup_title'],
          'stats' => $group_stats,
        );
      }
      $this->assign('stats', $output_stats);
    }
  }

  /**
   * Function to actually build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {
    // Create the Submit Button.
    $buttons = array(
      array(
        'type' => 'submit',
        'name' => ts('Sync Contacts'),
      ),
    );

    // Add the Buttons.
    $this->addButtons($buttons);
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return None
   */
  public function postProcess() {
    $runner = self::getRunner();
    if ($runner) {
      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    } else {
      CRM_Core_Session::setStatus(ts('Nothing to sync. Make Google Group settings are configured for the groups with enough members.'));
    }
  }

  static function getRunner($skipEndUrl = FALSE) {
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    // reset push stats
    $stats = [];
    GG::setStats($stats);

    // We need to process one list at a time.
    $groups = GG::getGroupsToSync(array(), null);
    if (!$groups) {
      // Nothing to do.
      return FALSE;
    }

    // Each list is a task.
    $groupCount = 1;
    foreach ($groups as $group_id => $details) {
      $identifier = "Group" . $groupCount++ . " " . $details['civigroup_title'];
      $task  = new CRM_Queue_Task(
        array ('CRM_Googlegroups_Form_Sync', 'syncPushList'),
        array($details['group_id'], $identifier),
        "Preparing queue for $identifier"
      );
      // Add the Task to the Queue
      $queue->createItem($task);
    }

    // Setup the Runner
		$runnerParams = array(
      'title' => ts('Googlegroup Sync: CiviCRM to Googlegroup'),
      'queue' => $queue,
      'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
      'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS, TRUE, NULL, FALSE),
    );
		// Skip End URL to prevent redirect
		// if calling from cron job
		if ($skipEndUrl == TRUE) {
			unset($runnerParams['onEndUrl']);
		}
    $runner = new CRM_Queue_Runner($runnerParams);

    //static::updatePushStats($stats);

    return $runner;
  }

  /**
   * Set up (sub)queue for syncing a Google Groups List.
   */
  static function syncPushList(CRM_Queue_TaskContext $ctx, $groupID, $identifier) {

    // Split the work into parts:
    // @todo 'force' method not implemented here.

    // Add the Google Groups collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Googlegroups_Form_Sync', 'syncPushCollectGoogleGroups'),
      array($groupID),
      "$identifier: Fetched data from Google Groups"
    ));

    // Add the CiviCRM collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Googlegroups_Form_Sync', 'syncPushCollectCiviCRM'),
      array($groupID),
      "$identifier: Fetched data from CiviCRM"
    ));

    // Add the removals task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Googlegroups_Form_Sync', 'syncPushRemove'),
      array($details['group_id'], $identifier),
      "$identifier: Removed those who should no longer be subscribed"
    ));

    // Add the batchUpdate to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Googlegroups_Form_Sync', 'syncPushAdd'),
      array($details['group_id'], $identifier),
      "$identifier: Added new subscribers and updating existing data changes"
    ));

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect Google Groups data into temporary working table.
   */
  static function syncPushCollectGoogleGroups(CRM_Queue_TaskContext $ctx, $groupID) {
    $stats[$groupID]['googlegroups_count'] = static::syncCollectGoogle($groupID);
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  static function syncPushCollectCiviCRM(CRM_Queue_TaskContext $ctx, $groupID) {
    $stats[$groupID]['civi_count'] = static::syncCollectCiviCRM($groupID);
    static::updatePushStats($stats);
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Unsubscribe contacts that are subscribed at Google Groups but not in our list.
   */
  static function syncPushRemove(CRM_Queue_TaskContext $ctx, $groupID, $identifier) {
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT m.email, m.euid
       FROM tmp_googlegroups_g m
       WHERE NOT EXISTS (
         SELECT email FROM tmp_googlegroups_c c WHERE c.email = m.email
       );");

    $batch = array();
    $stats[$groupID]['removed'] = 0;
    while ($dao->fetch()) {
      //$batch[] = array('email' => $dao->email,'euid' => $dao->euid, 'leid' => $dao->leid);
      $batch[] = $dao->email;
      $stats[$groupID]['removed']++;
    }
    // Log and email the batch unsubscribe details
    if ($batch) {
	CRM_Core_Error::debug_var( 'Google Group batchUnsubscribe [$groupID, $batch] ', ['groupID' => $groupID, 'batch' => $batch],  true, true, "googlegroup");

        $mail_params = self::EMAIL_PARAMS;

        $mail_params["subject"] = sprintf( $mail_params["subject"] , $identifier);
        $mail_params["text"] = sprintf($mail_params["text"], "Unsubscribing:", implode("\n", $batch), $groupID);

        CRM_Utils_Mail::send($mail_params);
	}


    if (!empty($batch)) {
      $results = civicrm_api('Googlegroups', 'deletemember', array('version' => 3, 'group_id' => $groupID, 'member' => $batch));
      // Finally we can delete the emails that we just processed from the mailchimp temp table.
      CRM_Core_DAO::executeQuery(
        "DELETE FROM tmp_googlegroups_g
        WHERE NOT EXISTS (
          SELECT email FROM tmp_googlegroups_c c WHERE c.email = tmp_googlegroups_g.email
        );");
    }
    static::updatePushStats($stats);
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Batch update Google Groups with new contacts that need to be subscribed, or have changed data.
   *
   * This also does the clean-up tasks of removing the temporary tables.
   */
  static function syncPushAdd(CRM_Queue_TaskContext $ctx, $groupID, $identifier) {
    
    // To avoid 'already member exists' error thrown, so remove contacts alreay in Google from civi temp table 
    CRM_Core_DAO::executeQuery(
      "DELETE FROM tmp_googlegroups_c
       WHERE EXISTS (
         SELECT email FROM tmp_googlegroups_g m WHERE m.email = tmp_googlegroups_c.email
       );");

    $dao = CRM_Core_DAO::executeQuery( "SELECT * FROM tmp_googlegroups_c;");
    // Loop the $dao object to make a list of emails to subscribe/update
    $batch = [];
    $stats = [$groupID => ['added' => 0]];
    while ($dao->fetch()) {
      $batch[] = $dao->email;
      $stats[$groupID]['added']++;
    }
    if (!empty($batch)) {
      $results = civicrm_api('Googlegroups', 'subscribe', array('version' => 3, 'group_id' => $groupID, 'emails' => $batch, 'role' => 'MEMBER'));
    }
    // Log and email batch subscribe details
    if ($batch) {
	CRM_Core_Error::debug_var( 'Google Group batchSubscribe [$groupID, $batch] ', ['groupID' => $groupID, 'batch' => $batch],  true, true, "googlegroup");

	$mail_params = self::EMAIL_PARAMS;

        $mail_params["subject"] = sprintf( $mail_params["subject"] , $identifier);
	$mail_params["text"] = sprintf($mail_params["text"], "Subscribing:", implode("\n", $batch), $groupID);

	CRM_Utils_Mail::send($mail_params);

	}

    static::updatePushStats($stats);

    // Finally, finish up by removing the two temporary tables
    CRM_Core_DAO::executeQuery("DROP TABLE tmp_googlegroups_g;");
    CRM_Core_DAO::executeQuery("DROP TABLE tmp_googlegroups_c;");

    $stats = GG::getStats();
    CRM_Core_Error::debug_var("gg sync stats in the end", $stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }


  /**
   * Collect Google Group data into temporary working table.
   */
  static function syncCollectGoogle($groupID) {
    // Create a temporary table.
    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_googlegroups_g;");
    $dao = CRM_Core_DAO::executeQuery(
      "CREATE TABLE tmp_googlegroups_g (
        email VARCHAR(200),
        euid VARCHAR(100),
        PRIMARY KEY (email));");
    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    $insert = $db->prepare('INSERT INTO tmp_googlegroups_g VALUES(?, ?)');
    $googleGroupMembers = civicrm_api('Googlegroups', 'getmembers', array('version' => 3, 'group_id' => $groupID));
    CRM_Core_Error::debug_var('civicrm_api3_googlegroups_getmembers $googleGroupMembers', $googleGroupMembers);
    foreach ($googleGroupMembers['values'] as $memberId => $memberEmail) {
      $db->execute($insert, array($memberEmail, $memberId));
    }
    $db->freePrepared($insert);
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_googlegroups_g");
    $dao->fetch();
    return $dao->c;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  static function syncCollectCiviCRM($groupID) {

    // Nb. these are temporary tables but we don't use TEMPORARY table because they are
    // needed over multiple sessions because of queue.
    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_googlegroups_c;");
    $dao = CRM_Core_DAO::executeQuery("CREATE TABLE tmp_googlegroups_c (
        contact_id INT(10) UNSIGNED NOT NULL,
        email_id INT(10) UNSIGNED NOT NULL,
        email VARCHAR(200),
        first_name VARCHAR(100),
        last_name VARCHAR(100),
        PRIMARY KEY (email_id, email)
        );");
    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    $insert = $db->prepare('INSERT INTO tmp_googlegroups_c VALUES(?, ?, ?, ?, ?)');

    // We only care about CiviCRM groups that are mapped to this Google Group:
    $mapped_groups = GG::getGroupsToSync(array(), $groupID);
    foreach ($mapped_groups as $group_id => $details) {
      CRM_Contact_BAO_GroupContactCache::loadAll($group_id);
      $groupContact = GG::getGroupContactObject($group_id);
      while ($groupContact->fetch()) {
        // Find the contact, for the name fields
        $contact = new CRM_Contact_BAO_Contact();
        $contact->id = $groupContact->contact_id;
        $contact->is_deleted = 0;
        $contact->find(TRUE);
        if ($contact->is_opt_out || $contact->do_not_email) {
          //@todo update stats.
          continue;
        }

        $email = self::getEmailObj($groupContact->contact_id);
        // If no email, it's like they're not there.
        if (!$email) {
          continue;
        }

        // run insert prepared statement
        $db->execute($insert, array($contact->id, $email->id, $email->email, $contact->first_name, $contact->last_name));
      }
    }

    // Tidy up.
    $db->freePrepared($insert);
    // count
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_googlegroups_c");
    $dao->fetch();
    return $dao->c;
  }

  /**
   * Check for any google emails. If no google emails, return primary.
   *
   */
  static function getEmailObj($cid) {
    $gEmail = new CRM_Core_BAO_Email();
    $gEmail->contact_id = $cid;
    $gEmail->on_hold = 0;

    // return any google email
    $locationTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Address', 'location_type_id');
    $gLocTypeId    = array_search('Google', $locationTypes);
    if ($gLocTypeId) {
      $gEmail->location_type_id = $gLocTypeId;
      if ($gEmail->find(TRUE) && $gEmail->email) {
        return $gEmail;
      }
    }

    // return primary email, if no google email
    $pEmail = new CRM_Core_BAO_Email();
    $pEmail->contact_id = $cid;
    $pEmail->on_hold = 0;
    $pEmail->is_primary = TRUE;
    if ($pEmail->find(TRUE) && $pEmail->email) {
      return $pEmail;
    }
    return FALSE;
  }

  /**
   * Update the push stats setting.
   */
  static function updatePushStats($updates) {
    $stats = GG::getStats();
    foreach ($updates as $groupId=>$settings) {
      foreach ($settings as $key=>$val) {
        $stats[$groupId][$key] = $val;
      }
    }
    GG::setStats($stats);
  }
}
