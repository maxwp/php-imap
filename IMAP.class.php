<?php
/**
 * WebProduction Packages
 * Copyright (C) 2007-2015 WebProduction <webproduction.ua>
 *
 * This program is commetcial software; you can not redistribute it and/or
 * modify it under any terms.
 */

/**
 * IMAP package.
 * See ./docs/ for examples.
 *
 * @author Maxim Miroshnichenko <max@webproduction.ua>
 * @copyright WebProduction
 * @package IMAP
 */
class IMAP {

    /**
     * Create new IMAP object.
     *
     * Defaults: port = 143
     * Defaults: options = novalidate-cert
     *
     * @param string $host
     * @param string $username
     * @param string $password
     * @param int $port
     * @param string $optionString
     */
    public function __construct($host, $username, $password, $port = false, $optionString = false) {
        if (!$port) {
            $port = 143;
        }

        if (!$optionString) {
            $optionString = '/novalidate-cert';
        }

        $this->_host = $host;
        $this->_port = $port;
        $this->_username = $username;
        $this->_password = $password;
        $this->_optionString = $optionString;
    }

    /**
     * Connect to IMAP server
     *
     * @throws IMAP_Exception
     */
    public function connect() {
        $this->_imapRef = '{'.$this->_host.':'.$this->_port.$this->_optionString.'}';

        $this->_imapConnection = imap_open(
            $this->_imapRef,
            $this->_username,
            $this->_password
        );

        $this->_checkErrors();
    }

    /**
     * Disconnect from IMAP server
     *
     * @throws IMAP_Exception
     */
    public function disconnect() {
        if (function_exists('imap_gc')) {
            imap_gc($this->_getIMAPConnection(), IMAP_GC_ELT);
            imap_gc($this->_getIMAPConnection(), IMAP_GC_ENV);
            imap_gc($this->_getIMAPConnection(), IMAP_GC_TEXTS);
        }
        imap_close($this->_getIMAPConnection());

        $this->_checkErrors();
    }

    /**
     * Expunge mailbox flags. If you flags messages during IMAP connection
     * expunge() will save flags.
     *
     * No need to call this method() every time.
     *
     * @throws IMAP_Exception
     */
    public function expunge() {
        imap_expunge($this->_getIMAPConnection());
        $this->_checkErrors();
    }

    /**
     * Get list of mailboxes
     *
     * @param string $pattern
     *
     * @return array
     */
    public function getMailboxArray($pattern = '*') {
        $list = imap_list($this->_getIMAPConnection(), $this->_imapRef, $pattern);
        $this->_checkErrors();

        return $list;
    }

    /**
     * Get messages by mailbox.
     * Returns array of UIDs.
     *
     * @param string $mailbox
     *
     * @return array
     */
    public function getMessageArray($mailbox) {
        $this->_changeMailbox($mailbox);

        $uidArray = imap_sort($this->_getIMAPConnection(), SORTARRIVAL, 1, SE_UID);
        $this->_checkErrors();

        if (!$uidArray) {
            throw new IMAP_Exception('No UIDs in mailbox '.$mailbox);
        }

        return $uidArray;
    }

    /**
     * Get structured message by mailbox and UID.
     * Returns a structured array.
     * If $headersArray == true - return only headers, without parsing content.
     *
     * @param string $mailbox
     * @param int $uid
     * @param bool $headersOnly
     *
     * @return array
     */
    public function getMessage($mailbox, $uid, $headersOnly = false) {
        $mailbox = $this->_changeMailbox($mailbox);

        $result = array();
        $result['uid'] = $uid;

        // auto-submitted rejects
        // issue #38889
        $headerString = @imap_fetchheader($this->_imapConnection, $uid, FT_UID);
        if (preg_match("/Auto-Submitted\:(\s*)auto-replied/ius", $headerString)) {
            $result['autosubmitted'] = true;
        } else {
            $result['autosubmitted'] = false;
        }

        $headersArray = imap_fetch_overview($this->_getIMAPConnection(), $uid, FT_UID);

        $date = @$headersArray[0]->date.'';
        $date = date('Y-m-d H:i:s', strtotime($date));

        $messageID = @$headersArray[0]->message_id.'';
        $subject = @$headersArray[0]->subject.'';
        $from = @$headersArray[0]->from.'';
        $to = @$headersArray[0]->to.'';
        if (isset($headersArray[0]->cc)) {
            $cc = @$headersArray[0]->cc.'';
        } else {
            $cc = '';
        }

        $subject = $this->_parseSubject($subject);
        $from = $this->_parseEmail($from);
        $toArray = $this->_parseEmailArray($to.', '.$cc);

        $result['msgno'] = $headersArray[0]->msgno.'';
        $result['flagged'] = (bool) $headersArray[0]->flagged.'';
        $result['answered'] = (bool) $headersArray[0]->answered.'';
        $result['seen'] = (bool) $headersArray[0]->seen.'';
        $result['draft'] = (bool) $headersArray[0]->draft.'';
        $result['deleted'] = (bool) $headersArray[0]->deleted.'';
        $result['recent'] = (bool) $headersArray[0]->recent.'';

        // issue #36169 - rejects mailer-daemon@
        if (preg_match("/^mailer-daemon@/ius", $from)) {
            $result['mailerdaemon'] = true;
        } else {
            $result['mailerdaemon'] = false;
        }

        // issue #55890 - rejects spam
        $result['spam'] = false;
        if (preg_match("/^\[SPAM\]/ius", $subject)) {
            $result['spam'] = true;
        } elseif (substr_count($subject, '***Spam***')) {
            $result['spam'] = true;
        }

        // name of mailbox
        $mailboxName = '';
        if (preg_match("/\}(.+?)$/ius", $mailbox, $r)) {
            $mailboxName = trim($r[1]);
            $mailboxName = strtolower($mailboxName);
        }

        $result['from'] = $from;
        $result['to'] = $toArray;
        $result['date'] = $date;
        $result['mailbox'] = $mailboxName;
        $result['subject'] = $subject;
        $result['messageid'] = $messageID;
        $result['subjectgroup'] = $this->_parseSubjectGroup($subject);

        if ($headersOnly) {
            return $result;
        }

        // получаем структуру письма.
        $structure = imap_fetchstructure($this->_getIMAPConnection(), $uid, FT_UID);

        // sturcture can be a tree (parts), process it to linear array
        $a = $this->_parseParts($structure);

        $partsArray = array();
        $partsText = false;
        $partsHTML = false;

        foreach ($a as $section => $part) {
            if (!empty($part->parts)) {
                continue;
            }

            if (!isset($part->type)) {
                continue;
            }

            // get content of section
            $content = imap_fetchbody($this->_getIMAPConnection(), $uid, $section, FT_UID | FT_PEEK);

            // decode content
            $content = $this->_decodeContent($part->encoding.'', $content);

            $filename = false;

            if (is_array($part->parameters)) {
                foreach ($part->parameters as $p) {
                    // read charset
                    if (strtolower($p->attribute.'') == 'charset') {
                        $content = @iconv(
                            $p->value.'',
                            'utf-8',
                            $content
                        );
                    }

                    // read file name
                    if (strtolower($p->attribute.'') == 'name'
                    || strtolower($p->attribute.'') == 'name*'
                    || strtolower($p->attribute.'') == 'filename'
                    || strtolower($p->attribute.'') == 'filename*') {
                        $filename = $p->value.'';
                        $filename = $this->_parseSubject($filename);
                    }
                }

            }

            $type = $this->_detectType($part);

            if (substr_count($type, 'multipart/')) {
                continue;
            }
            if (substr_count($type, 'message/')) {
                continue;
            }

            // save part
            $partsArray[] = array(
            'name' => $filename,
            'content' => $content,
            'type' => $type,
            );

            // if this text content
            if ($type == 'text/plain' && !$partsText) {
                // for big texts
                if (mb_strlen($partsText) <= 5000) {
                    $partsText = $content;
                    unset($partsArray[count($partsArray) - 1]);
                }
            }

            // if HTML part without text
            if ($type == 'text/html') {
                if (!$partsText) {
                    // если html'a не сильно много - считаем это письмом
                    if (mb_strlen($partsText) <= 10000) {
                        $partsText = strip_tags($content);
                        unset($partsArray[count($partsArray) - 1]);
                    }
                } elseif (!$filename) {
                    unset($partsArray[count($partsArray) - 1]);
                }
            }
        }

        $result['text'] = $partsText;
        $result['html'] = $partsHTML;
        $result['file'] = $partsArray;

        return $result;
    }

    /**
     * Delete message by mailbox and UID
     *
     * @param string $mailbox
     * @param int $uid
     * @param bool $flagOnly
     *
     * @return bool
     */
    public function deleteMessage($mailbox, $uid, $flagOnly = false) {
        $x = $this->_changeMailbox($mailbox);
        $result = imap_delete($this->_getIMAPConnection(), $uid, FT_UID);
        $this->_checkErrors();
        if (!$flagOnly) {
            $this->expunge();
        }
        return $result;
    }

    /**
     * Flag message
     *
     * @param string $mailbox
     * @param int $uid
     * @param string $flag
     *
     * @return bool
     */
    public function flagMessage($mailbox, $uid, $flag) {
        $this->_changeMailbox($mailbox);
        $result = imap_setflag_full($this->_getIMAPConnection(), $uid, $flag, ST_UID);
        $this->_checkErrors();
        return $result;
    }

    /**
     * Un flag message
     *
     * @param string $mailbox
     * @param int $uid
     * @param string $flag
     *
     * @return bool
     */
    public function unflagMessage($mailbox, $uid, $flag) {
        $this->_changeMailbox($mailbox);
        $result = imap_clearflag_full($this->_getIMAPConnection(), $uid, $flag, ST_UID);
        $this->_checkErrors();
        return $result;
    }

    /**
     * Set message seen or unseen
     *
     * @param string $mailbox
     * @param int $uid
     * @param bool $value
     * @param bool $flagOnly
     */
    public function setMessageSeen($mailbox, $uid, $value, $flagOnly = false) {
        if ($value) {
            $this->flagMessage($mailbox, $uid, '\\Seen');
        } else {
            $this->unflagMessage($mailbox, $uid, '\\Seen');
        }

        if (!$flagOnly) {
            $this->expunge();
        }
    }

    /**
     * Set message flagged or unflagged
     *
     * @param string $mailbox
     * @param int $uid
     * @param bool $value
     * @param bool $flagOnly
     */
    public function setMessageFlagged($mailbox, $uid, $value) {
        if ($value) {
            $this->flagMessage($mailbox, $uid, '\\Flagged');
        } else {
            $this->unflagMessage($mailbox, $uid, '\\Flagged');
        }
        $this->expunge();
    }

    /**
     * Set message answered or unanswered
     *
     * @param string $mailbox
     * @param int $uid
     * @param bool $value
     * @param bool $flagOnly
     */
    public function setMessageAnswered($mailbox, $uid, $value) {
        if ($value) {
            $this->flagMessage($mailbox, $uid, '\\Answered');
        } else {
            $this->unflagMessage($mailbox, $uid, '\\Answered');
        }
        $this->expunge();
    }

    /**
     * Get IMAP connection
     *
     * @access private
     *
     * @return resource
     */
    private function _getIMAPConnection() {
        if (!$this->_imapConnection) {
            throw new IMAP_Exception("Not connected");
        }

        return $this->_imapConnection;
    }

    /**
     * Check IMAP errors
     *
     * @throws IMAP_Exception
     */
    private function _checkErrors() {
        $a = imap_errors();
        if ($a) {
            foreach ($a as $index => $x) {
                if (substr_count($x, 'Unexpected characters at end of address')) {
                    unset($a[$index]);
                }
            }
        }
        if ($a) {
            throw new IMAP_Exception($a);
        }
    }

    /**
     * Format subject
     *
     * @param string $subject
     *
     * @return string
     */
    private function _parseSubjectGroup($subject) {
        return preg_replace(
            "/^(\[?(Fw|Re|Fwd|Ответ|Ha|Rcpt)\s*((\[\s*\d+\s*\])|(\(\s*\d+\s*\)))?\s*:\s*)+/usi",
            '',
            trim($subject, ' ]')
        );
    }

    /**
     * Move parts to linear array
     *
     * @param resource $struct
     *
     * @return array
     */
    private function _parseParts($struct) {
        if (!empty($struct->parts)) {
            // There some sub parts
            foreach ($struct->parts as $count => $part) {
                $this->_add_part_to_array($part, ($count+1), $part_array);
            }
        } else {
            // Email does not have a seperate mime attachment for text
            $part_array[1] = $struct;
        }
        return $part_array;
    }

    /**
     * Sub function for create_part_array(). Only called by create_part_array() and itself.
     *
     * @param object $obj
     * @param int $partno
     * @param array $part_array
     */
    private function _add_part_to_array($obj, $partno, &$part_array) {
        $part_array[$partno] = $obj;

        if ($obj->type == 2) {
            // Check to see if the part is an attached email message,
            // as in the RFC-822 type
            if (!empty($obj->parts)) {
                // Check to see if the email has parts
                foreach ($obj->parts as $count => $part) {
                    // Iterate here again to compensate for the broken
                    // way that imap_fetchbody() handles attachments
                    if (!empty($part->parts)) {
                        foreach ($part->parts as $count2 => $part2) {
                            $this->_add_part_to_array($part2, $partno.".".($count2+1), $part_array);
                        }
                    } else {    // Attached email does not have a seperate mime attachment for text
                        $part_array[$partno.'.'.($count+1)] = $obj;
                    }
                }
            } else {
                // Not sure if this is possible
                $part_array[$partno.'.1'] = $obj;
            }
        } else {
            // If there are more sub-parts, expand them out.
            if (!empty($obj->parts)) {
                foreach ($obj->parts as $count => $p) {
                    $this->_add_part_to_array($p, $partno.".".($count+1), $part_array);
                }
            }
        }
    }

    /**
     * Decode content
     *
     * @param string $encoding
     * @param string $content
     *
     * @return string
     */
    private function _decodeContent($encoding, $content) {
        if ($encoding == 3) {
            return base64_decode($content);
        } elseif ($encoding == 4) {
            return quoted_printable_decode($content);
        }
        return $content;
    }

    /**
     * Parse subject
     *
     * @param string $subject
     *
     * @return string
     */
    private function _parseSubject($subject) {
        $a = imap_mime_header_decode($subject);
        $r = '';
        foreach ($a as $x) {
            if ($x->charset.'' == 'default') {
                $r .= $x->text.'';
            } else {
                $r .= @iconv($x->charset.'', 'utf-8//IGNORE', $x->text.'');
            }
        }

        $r = preg_replace("/^UTF-8''(.+?)\./iuse", 'urldecode("$1").".";', $r);

        return $r;
    }

    /**
     * Parse clear email address
     *
     * @param string $email
     *
     * @return string
     */
    private function _parseEmail($email) {
        $x = trim($this->_parseSubject($email));
        if (preg_match("/\<(.+?)\>/uis", $x, $email)) {
            $x = $email[1];
        }

        $x = substr($x, 0, 64);
        return strtolower($x);
    }

    /**
     * Parse clear email address array
     *
     * @param string $email
     *
     * @return array
     */
    private function _parseEmailArray($email) {
        $to = explode(',', $email);
        $toArray = array();
        foreach ($to as $x) {
            $x = $this->_parseEmail($x);
            if (Checker::CheckEmail($x)) {
                $toArray[] = strtolower($x);
            }
        }
        return $toArray;
    }

    /**
     * Detect content type
     *
     * @param string $part
     *
     * @return string
     */
    private function _detectType($part) {
        $type = $part->type.'';
        $subtype = $part->subtype.'';

        if ($type == 0) {
            $type = "text/";
        } elseif ($type == 1) {
            $type = "multipart/";
        } elseif ($type == 2) {
            $type = "message/";
        } elseif ($type == 3) {
            $type = "application/";
        } elseif ($type == 4) {
            $type = "audio/";
        } elseif ($type == 5) {
            $type = "image/";
        } elseif ($type == 6) {
            $type = "video";
        } elseif ($type == 7) {
            $type = "other/";
        }

        $type .= $subtype;
        $type = strtolower($type);
        return $type;
    }

    /**
     * Change mailbox during connection.
     * Method returns mailbox reference name.
     *
     * @param string $mailbox
     *
     * @return string
     */
    private function _changeMailbox($mailbox) {
        if (!$mailbox) {
            throw new IMAP_Exception('Invalid mailbox');
        }

        if (!substr_count($mailbox, $this->_imapRef)) {
            $mailbox = $this->_imapRef.$mailbox;
        }

        if ($this->_imapMailboxCurrent != $mailbox) {
            if (!imap_reopen($this->_getIMAPConnection(), $mailbox, null, 3)) {
                throw new IMAP_Exception('Can not change mailbox to '.$mailbox);
            }
            $this->_checkErrors();
        }

        $this->_imapMailboxCurrent = $mailbox;

        return $this->_imapMailboxCurrent;
    }

    private $_host;

    private $_port;

    private $_username;

    private $_password;

    private $_optionString;

    private $_imapConnection;

    private $_imapMailboxCurrent;

    private $_imapRef;

}