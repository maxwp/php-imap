<?php

require(dirname(__FILE__).'/IMAP/include.php');

try {
    // connect to IMAP server
    $imap = new IMAP('mail.domain.com', 'username@domain.com', 'password');
    $imap->connect();

    // get list of all mailboxes
    $mailboxArray = $imap->getMailboxArray();
    print_r($mailboxArray);

    // prints:
    //    inbox
    //    sent
    //    trash
    //    junk
    //    ...

    // get all messages in INBOX (print array of UID)
    $uidArray = $imap->getMessageArray('inbox');
    print_r($uidArray);

    // prints:
    //    1750
    //    1751
    //    1752
    //    1753
    //    1754
    //    1755
    //    ...

    // get message headers by UID=1751. Prints formatted message array.
    $headerArray = $imap->getMessage('inbox', 1751, true);
    print_r($headerArray);

    // prints:
    //    [uid] => 1751
    //    [autosubmitted] => 0
    //    [msgno] => 5371
    //    [flagged] => 0
    //    [answered] => 0
    //    [seen] => 1
    //    [draft] => 0
    //    [deleted] => 0
    //    [recent] => 0
    //    [mailerdaemon] => 0
    //    [spam] => 0
    //    [from] => from@domain.com
    //    [to] => Array
    //        (
    //            [0] => to@domain.com
    //        )
    //    [date] => 2014-12-15 18:01:08
    //    [mailbox] => inbox
    //    [subject] => Re: subject name
    //    [messageid] => <3d380283bf74225f2b1ea39756b651ff@domain.com>
    //    [subjectgroup] => subject name

    // get full message by UID=1751. Prints formatted message array.
    $headerArray = $imap->getMessage('inbox', 1751);
    print_r($headerArray);

    // prints:
    //    [uid] => 1751
    //    [autosubmitted] => 0
    //    [msgno] => 5371
    //    [flagged] => 0
    //    [answered] => 0
    //    [seen] => 1
    //    [draft] => 0
    //    [deleted] => 0
    //    [recent] => 0
    //    [mailerdaemon] => 0
    //    [spam] => 0
    //    [from] => from@domain.com
    //    [to] => Array
    //        (
    //            [0] => to@domain.com
    //        )
    //    [date] => 2014-12-15 18:01:08
    //    [mailbox] => inbox
    //    [subject] => Re: subject name
    //    [messageid] => <3d380283bf74225f2b1ea39756b651ff@domain.com>
    //    [subjectgroup] => subject name
    //    [text] = ... text content of message
    //    [html] = ... html content of message
    //    [file] => Array
    //        (
    //            [0] => Array
    //                (
    //                    [name] => file.png
    //                    [type] => image/png
    //                    [content] => ... (binary content of attachments)
    //                )
    //        )

    // set message as seen
    $imap->setMessageSeen('inbox', 175, true);

    // set message as unseen
    $imap->setMessageSeen('inbox', 175, false);

    // set message as flagged
    $imap->setMessageFlagged('inbox', 175, true);

    // set message as unflagged
    $imap->setMessageFlagged('inbox', 175, false);

    // set message as answered
    $imap->setMessageAnswered('inbox', 175, true);

    // set message as unanswered
    $imap->setMessageAnswered('inbox', 175, false);

    // mark group of messages as seen
    $imap->setMessageSeen('inbox', 171, true, true);
    $imap->setMessageSeen('inbox', 172, true, true);
    $imap->setMessageSeen('inbox', 173, true, true);
    // and saves it
    $imap->expunge();

    // delete message
    $imap->deleteMessage('inbox', 174);

    // mark group of messages as deleted
    $imap->deleteMessage('inbox', 175, true);
    $imap->deleteMessage('inbox', 176, true);
    $imap->deleteMessage('inbox', 177, true);
    // and delete it
    $imap->expunge();

    // disconnect from server
    $imap->disconnect();
} catch (Exception $imapEx) {
    print_r($imapEx);
}

