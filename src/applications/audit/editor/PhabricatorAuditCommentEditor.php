<?php

final class PhabricatorAuditCommentEditor extends PhabricatorEditor {

  /**
   * Load the PHIDs for all objects the user has the authority to act as an
   * audit for. This includes themselves, and any packages they are an owner
   * of.
   */
  public static function loadAuditPHIDsForUser(PhabricatorUser $user) {
    $phids = array();

    // TODO: This method doesn't really use the right viewer, but in practice we
    // never issue this query of this type on behalf of another user and are
    // unlikely to do so in the future. This entire method should be refactored
    // into a Query class, however, and then we should use a proper viewer.

    // The user can audit on their own behalf.
    $phids[$user->getPHID()] = true;

    $owned_packages = id(new PhabricatorOwnersPackageQuery())
      ->setViewer($user)
      ->withOwnerPHIDs(array($user->getPHID()))
      ->execute();
    foreach ($owned_packages as $package) {
      $phids[$package->getPHID()] = true;
    }

    // The user can audit on behalf of all projects they are a member of.
    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withMemberPHIDs(array($user->getPHID()))
      ->execute();
    foreach ($projects as $project) {
      $phids[$project->getPHID()] = true;
    }

    return array_keys($phids);
  }

  public static function getMailThreading(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    return array(
      'diffusion-audit-'.$commit->getPHID(),
      pht(
        'Commit %s',
        'r'.$repository->getCallsign().$commit->getCommitIdentifier()),
    );
  }

  public static function newReplyHandlerForCommit($commit) {
    $reply_handler = PhabricatorEnv::newObjectFromConfig(
      'metamta.diffusion.reply-handler');
    $reply_handler->setMailReceiver($commit);
    return $reply_handler;
  }

  private function renderMailBody(
    PhabricatorAuditComment $comment,
    $cname,
    PhabricatorObjectHandle $handle,
    PhabricatorMailReplyHandler $reply_handler,
    array $inline_comments) {
    assert_instances_of($inline_comments, 'PhabricatorInlineCommentInterface');

    $commit = $this->commit;
    $actor = $this->getActor();
    $name = $actor->getUsername();

    $verb = PhabricatorAuditActionConstants::getActionPastTenseVerb(
      $comment->getAction());

    $body = new PhabricatorMetaMTAMailBody();
    if (!PhabricatorEnv::getEnvConfig('minimal-email', false)) {
      $body->addRawSection("{$name} {$verb} commit {$cname}.");
    }
    $body->addRawSection($comment->getContent());

    if ($inline_comments) {
      $block = array();

      $path_map = id(new DiffusionPathQuery())
        ->withPathIDs(mpull($inline_comments, 'getPathID'))
        ->execute();
      $path_map = ipull($path_map, 'path', 'id');

      foreach ($inline_comments as $inline) {
        $path = idx($path_map, $inline->getPathID());
        if ($path === null) {
          continue;
        }

        $start = $inline->getLineNumber();
        $len   = $inline->getLineLength();
        if ($len) {
          $range = $start.'-'.($start + $len);
        } else {
          $range = $start;
        }

        $content = $inline->getContent();
        $block[] = "{$path}:{$range} {$content}";
      }

      if (!PhabricatorEnv::getEnvConfig('minimal-email', false)) {
        $body->addTextSection(pht('INLINE COMMENTS'), implode("\n", $block));
      } else {
        $body->addRawSection(implode("\n", $block));
      }
    }

    if (!PhabricatorEnv::getEnvConfig('minimal-email', false)) {
      $body->addTextSection(
        pht('COMMIT'),
        PhabricatorEnv::getProductionURI($handle->getURI()));
      $body->addReplySection($reply_handler->getReplyHandlerInstructions());
    } else {
      $body->addRawSection(PhabricatorEnv::getProductionURI(
        $handle->getURI()));
    }

    return $body->render();
  }
}
