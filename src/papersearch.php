<?php
// papersearch.php -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchOperator {
    public $op;
    public $unary;
    public $precedence;
    function __construct($what, $unary, $precedence) {
        $this->op = $what;
        $this->unary = $unary;
        $this->precedence = $precedence;
    }

    static public $list;
}

SearchOperator::$list =
        array("(" => new SearchOperator("(", true, null),
              "NOT" => new SearchOperator("not", true, 6),
              "-" => new SearchOperator("not", true, 6),
              "+" => new SearchOperator("+", true, 6),
              "SPACE" => new SearchOperator("and2", false, 5),
              "AND" => new SearchOperator("and", false, 4),
              "OR" => new SearchOperator("or", false, 3),
              "XAND" => new SearchOperator("and2", false, 2),
              "XOR" => new SearchOperator("or", false, 2),
              "THEN" => new SearchOperator("then", false, 1),
              ")" => null);

class SearchTerm {
    var $type;
    var $link;
    var $flags;
    var $value;

    function __construct($t, $f = 0, $v = null, $other = null) {
        $this->type = $t;
        $this->link = false;
        $this->flags = $f;
        $this->value = $v;
        if ($other) {
            foreach ($other as $k => $v)
                $this->$k = $v;
        }
    }
    static function combine($combiner, $terms) {
        if (!is_array($terms) && $terms)
            $terms = array($terms);
        if (count($terms) == 0)
            return null;
        else if ($combiner === "not") {
            assert(count($terms) == 1);
            return self::negate($terms[0]);
        } else if (count($terms) == 1)
            return $terms[0];
        else
            return new SearchTerm($combiner, 0, $terms);
    }
    static function negate($term) {
        if (!$term)
            return null;
        else if ($term->type === "not")
            return $term->value;
        else if ($term->type === "f")
            return new SearchTerm("t");
        else if ($term->type === "t")
            return new SearchTerm("f");
        else
            return new SearchTerm("not", 0, $term);
    }
    static function make_float($float) {
        return new SearchTerm("float", 0, null, array("float" => $float));
    }
    static function merge_float(&$float1, $float2) {
        if (!$float1 || !$float2)
            return $float1 ? : $float2;
        else {
            foreach ($float2 as $k => $v)
                if ($k === "sort" && isset($float1["sort"]))
                    array_splice($float1["sort"], count($float1["sort"]), 0, $v);
                else if (is_array(@$float1[$k]) && is_array($v))
                    $float1[$k] = array_replace_recursive($float1[$k], $v);
                else
                    $float1[$k] = $v;
            return $float1;
        }
    }
    static function extract_float(&$float, $qe) {
        if (!isset($float))
            $float = null;
        if ($qe && ($qefloat = $qe->get("float"))) {
            $float = self::merge_float($float, $qefloat);
            return $qe->type === "float" ? null : $qe;
        } else
            return $qe;
    }
    static function combine_float($float, $combiner, $terms) {
        $qe = self::combine($combiner, $terms);
        if ($float && !$qe)
            return SearchTerm::make_float($float);
        else {
            if ($float)
                $qe->set("float", $float);
            return $qe;
        }
    }
    function isfalse() {
        return $this->type === "f";
    }
    function islistcombiner() {
        return $this->type === "and" || $this->type === "and2"
            || $this->type === "or" || $this->type === "then";
    }
    function set($k, $v) {
        $this->$k = $v;
    }
    function get($k, $defval = null) {
        return isset($this->$k) ? $this->$k : $defval;
    }
    function set_float($k, $v) {
        if (!isset($this->float))
            $this->float = array();
        $this->float[$k] = $v;
    }
    function get_float($k, $defval = null) {
        if (isset($this->float) && isset($this->float[$k]))
            return $this->float[$k];
        else
            return $defval;
    }
}

class SearchReviewValue {
    public $countexpr;
    public $contactsql;
    private $_contactset;
    public $fieldsql;
    public $compar = 0;
    public $allowed = 0;
    public $view_score;

    static public $opmap = array("" => 2, "#" => 2, "=" => 2, "==" => 2,
                                 "!" => 5, "!=" => 5, "≠" => 5,
                                 "<" => 1, "<=" => 3, "≤" => 3,
                                 "≥" => 6, ">=" => 6, ">" => 4);
    static public $oparray = array(false, "<", "=", "<=", ">", "!=", ">=", false);

    function __construct($countexpr, $contacts = null, $fieldsql = null,
                         $view_score = null) {
        $this->countexpr = $countexpr;
        if (!$contacts || is_string($contacts))
            $this->contactsql = $contacts;
        else
            $this->contactsql = sql_in_numeric_set($contacts);
        $this->_contactset = $contacts;
        $this->fieldsql = $fieldsql;
        if (preg_match('/\A([=!<>]=?|≠|≤|≥)(-?\d+)\z/', $countexpr, $m)) {
            $this->allowed |= self::$opmap[$m[1]];
            $this->compar = (int) $m[2];
        }
        $this->view_score = $view_score;
    }
    function test($n) {
        return self::compare($n, $this->allowed, $this->compar);
    }
    static function compare($x, $compar, $y) {
        if (!is_int($compar))
            $compar = self::$opmap[$compar];
        if ($x > $y)
            return ($compar & 4) != 0;
        else if ($x == $y)
            return ($compar & 2) != 0;
        else
            return ($compar & 1) != 0;
    }
    public function conservative_countexpr() {
        if ($this->allowed & 1)
            return ">=0";
        else
            return ($this->allowed & 2 ? ">=" : ">") . $this->compar;
    }
    static function negate_countexpr($countexpr) {
        $t = new SearchReviewValue($countexpr);
        if ($t->allowed)
            return self::$oparray[$t->allowed ^ 7] . $t->compar;
        else
            return $countexpr;
    }
    function restrictContact($contactid) {
        if (!$this->_contactset)
            $cset = array($contactid);
        else if (!is_array($this->_contactset))
            $cset = $this->_contactset . " and \3=$contactid";
        else if (in_array($contactid, $this->_contactset))
            $cset = array($contactid);
        else
            return null;
        return new SearchReviewValue($this->countexpr, $cset, $this->fieldsql);
    }
    function contactWhere($fieldname) {
        return str_replace("\3", $fieldname, "\3" . $this->contactsql);
    }
    static function any() {
        return new SearchReviewValue(">0", null);
    }
    static function canonical_comparator($text) {
        $text = trim($text);
        if (($x = self::$opmap[$text]))
            return self::$oparray[$x];
        else
            return false;
    }
}

class SearchQueryInfo {
    public $tables = array();
    public $columns = array();
    public $negated = false;

    public function add_table($table, $joiner = false) {
        assert($joiner || !count($this->tables));
        $this->tables[$table] = $joiner;
    }
    public function add_column($name, $expr) {
        assert(!isset($this->columns[$name]) || $this->columns[$name] === $expr);
        $this->columns[$name] = $expr;
    }
    public function add_rights_columns() {
        global $Conf;
        if (!isset($this->columns["managerContactId"]))
            $this->columns["managerContactId"] = "Paper.managerContactId";
        if (!isset($this->columns["leadContactId"]))
            $this->columns["leadContactId"] = "Paper.leadContactId";
    }
}

class ContactSearch {
    const F_SQL = 1;
    const F_TAG = 2;
    const F_PC = 4;
    const F_QUOTED = 8;
    const F_NOUSER = 16;

    public $type;
    public $text;
    public $me_cid;
    private $cset = null;
    public $ids = false;
    private $only_pc = false;
    private $contacts = false;
    public $warn_html = false;

    public function __construct($type, $text, $cid, $cset = null) {
        $this->type = $type;
        $this->text = $text;
        $this->me_cid = is_object($cid) ? $cid->contactId : $cid;
        $this->cset = $cset;
        if ($this->type & self::F_SQL) {
            $result = Dbl::qe("select contactId from ContactInfo where $text");
            $this->ids = Dbl::fetch_first_columns($result);
        }
        if ($this->ids === false && (!($this->type & self::F_QUOTED) || $this->text === ""))
            $this->ids = $this->check_simple();
        if ($this->ids === false && !($this->type & self::F_QUOTED) && ($this->type & self::F_TAG))
            $this->ids = $this->check_pc_tag();
        if ($this->ids === false && !($this->type & self::F_NOUSER))
            $this->ids = $this->check_user();
    }
    static function make_pc($text, $cid) {
        return new ContactSearch(self::F_PC | self::F_TAG, $text, $cid);
    }
    static function make_special($text, $cid) {
        return new ContactSearch(self::F_PC | self::F_TAG | self::F_NOUSER, $text, $cid);
    }
    static function make_cset($text, $cid, $cset) {
        return new ContactSearch(0, $text, $cid, $cset);
    }
    private function check_simple() {
        if ($this->text === ""
            || strcasecmp($this->text, "pc") == 0
            || (strcasecmp($this->text, "any") == 0 && ($this->type & self::F_PC)))
            return array_keys(pcMembers());
        else if (strcasecmp($this->text, "me") == 0
                 && (!($this->type & self::F_PC)
                     || (($pcm = pcMembers()) && isset($pcm[$this->me_cid]))))
            return array($this->me_cid);
        else
            return false;
    }
    private function check_pc_tag() {
        $need = $neg = false;
        $x = strtolower($this->text);
        if (substr($x, 0, 1) === "-") {
            $need = $neg = true;
            $x = substr($x, 1);
        }
        if (substr($x, 0, 1) === "#") {
            $need = true;
            $x = substr($x, 1);
        }

        $pctags = pcTags();
        if (isset($pctags[$x])) {
            $a = array();
            foreach (pcMembers() as $cid => $pc)
                if (stripos($pc->contactTags, " $x ") !== false)
                    $a[] = $cid;
            if ($neg && ($this->type & self::F_PC))
                return array_diff(array_keys(pcMembers()), $a);
            else if (!$neg)
                return $a;
            else {
                $result = Dbl::qe("select contactId from ContactInfo where contactId ?A", $a);
                return Dbl::fetch_first_columns($result);
            }
        } else if ($need) {
            $this->warn_html = "No such PC tag “" . htmlspecialchars($this->text) . "”.";
            return array();
        } else
            return false;
    }
    private function check_user() {
        if (strcasecmp($this->text, "anonymous") == 0
            && !$this->cset
            && !($this->type & self::F_PC)) {
            $result = Dbl::qe_raw("select contactId from ContactInfo where email regexp '^anonymous[0-9]*\$'");
            return Dbl::fetch_first_columns($result);
        }

        // split name components
        list($f, $l, $e) = Text::split_name($this->text, true);
        if ($f === "" && $l === "" && strpos($e, "@") === false)
            $n = $e;
        else
            $n = trim($f . " " . $l);

        // generalize email
        $estar = $e && strpos($e, "*") !== false;
        if ($e && !$estar) {
            if (preg_match('/\A(.*)@(.*?)((?:[.](?:com|net|edu|org|us|uk|fr|be|jp|cn))?)\z/', $e, $m))
                $e = ($m[1] === "" ? "*" : $m[1]) . "@*" . $m[2] . ($m[3] ? : "*");
            else
                $e = "*$e*";
        }

        // contact database if not restricted to PC or cset
        $result = null;
        if ($this->cset)
            $cs = $this->cset;
        else if ($this->type & self::F_PC)
            $cs = pcMembers();
        else {
            $q = array();
            if ($n !== "") {
                $x = sqlq_for_like(UnicodeHelper::deaccent($n));
                $q[] = "unaccentedName like '%" . preg_replace('/[\s*]+/', "%", $x) . "%'";
            }
            if ($e !== "") {
                $x = sqlq_for_like($e);
                $q[] = "email like '" . preg_replace('/[\s*]+/', "%", $x) . "'";
            }
            $result = Dbl::qe_raw("select firstName, lastName, unaccentedName, email, contactId, roles from ContactInfo where " . join(" or ", $q));
            $cs = array();
            while ($result && ($row = $result->fetch_object("Contact")))
                $cs[$row->contactId] = $row;
        }

        // filter results
        $nreg = $ereg = null;
        if ($n !== "")
            $nreg = PaperSearch::analyze_field_preg($n);
        if ($e !== "" && $estar)
            $ereg = '{\A' . str_replace('\*', '.*', preg_quote($e)) . '\z}i';
        else if ($e !== "") {
            $ereg = str_replace('@\*', '@(?:|.*[.])', preg_quote($e));
            $ereg = preg_replace('/\A\\\\\*/', '(?:.*[@.]|)', $ereg);
            $ereg = '{\A' . preg_replace('/\\\\\*$/', '(?:[@.].*|)', $ereg) . '\z}i';
        }

        $ids = array();
        foreach ($cs as $id => $acct)
            if ($ereg && preg_match($ereg, $acct->email)) {
                // exact email match trumps all else
                if (strcasecmp($e, $acct->email) == 0) {
                    $ids = array($id);
                    break;
                }
                $ids[] = $id;
            } else if ($nreg) {
                $n = $acct->firstName === "" || $acct->lastName === "" ? "" : " ";
                $n = $acct->firstName . $n . $acct->lastName;
                if (PaperSearch::match_field_preg($nreg, $n, $acct->unaccentedName))
                    $ids[] = $id;
            }

        Dbl::free($result);
        return $ids;
    }
    public function contacts() {
        global $Me;
        if ($this->contacts === false) {
            $this->contacts = array();
            $pcm = pcMembers();
            foreach ($this->ids as $cid)
                if ($this->cset && ($p = @$this->cset[$cid]))
                    $this->contacts[] = $p;
                else if (($p = @$pcm[$cid]))
                    $this->contacts[] = $p;
                else if ($Me->contactId == $cid)
                    $this->contacts[] = $Me;
                else
                    $this->contacts[] = Contact::find_by_id($cid);
        }
        return $this->contacts;
    }
    public function contact($i) {
        $this->contacts();
        return @$this->contacts[$i];
    }
}

class PaperSearch {

    const F_REVIEWTYPEMASK = 0x00007;
    const F_COMPLETE = 0x00008;
    const F_INCOMPLETE = 0x00010;
    const F_INPROGRESS = 0x00020;
    const F_NONCONFLICT = 0x00040;
    const F_AUTHOR = 0x00080;
    const F_REVIEWER = 0x00100;
    const F_AUTHORCOMMENT = 0x00200;
    const F_ALLOWRESPONSE = 0x00400;
    const F_ALLOWCOMMENT = 0x00800;
    const F_ALLOWDRAFT = 0x01000;
    const F_REQUIREDRAFT = 0x02000;
    const F_FALSE = 0x10000;
    const F_XVIEW = 0x20000;

    var $contact;
    public $cid;
    private $contactId;         // for backward compatibility
    var $privChair;
    private $amPC;

    var $limitName;
    var $qt;
    var $allowAuthor;
    private $fields;
    var $orderTags = array();
    private $_reviewer;
    private $_reviewer_fixed;
    var $matchPreg;
    private $urlbase;
    public $warnings = array();

    var $q;

    var $regex = array();
    public $overrideMatchPreg = false;
    private $contact_match = array();
    private $noratings = false;
    private $interestingRatings = array();
    private $needflags = 0;
    private $_query_options = array();
    private $reviewAdjust = false;
    private $_reviewAdjustError = false;
    private $_thenError = false;
    private $_ssRecursion = array();
    private $_allow_deleted = false;
    var $thenmap = null;
    var $headingmap = null;
    public $viewmap;
    public $sorters;

    private $_matches = null;

    static private $_sort_keywords = null;

    static private $_keywords = array("ti" => "ti", "title" => "ti",
        "ab" => "ab", "abstract" => "ab",
        "au" => "au", "author" => "au",
        "co" => "co", "collab" => "co", "collaborators" => "co",
        "re" => "re", "rev" => "re", "review" => "re",
        "sre" => "cre", "srev" => "cre", "sreview" => "cre",
        "cre" => "cre", "crev" => "cre", "creview" => "cre",
        "subre" => "cre", "subrev" => "cre", "subreview" => "cre",
        "ire" => "ire", "irev" => "ire", "ireview" => "ire",
        "pre" => "pre", "prev" => "pre", "preview" => "pre",
        "pri" => "pri", "primary" => "pri", "prire" => "pri", "prirev" => "pri",
        "cpri" => "cpri", "cprimary" => "cpri",
        "ipri" => "ipri", "iprimary" => "ipri",
        "sec" => "sec", "secondary" => "sec", "secre" => "sec", "secrev" => "sec",
        "csec" => "csec", "csecondary" => "csec",
        "isec" => "isec", "isecondary" => "isec",
        "ext" => "ext", "external" => "ext", "extre" => "ext", "extrev" => "ext",
        "cext" => "cext", "cexternal" => "cext",
        "iext" => "iext", "iexternal" => "iext",
        "cmt" => "cmt", "comment" => "cmt",
        "aucmt" => "aucmt", "aucomment" => "aucmt",
        "resp" => "response", "response" => "response",
        "draftresp" => "draftresponse", "draftresponse" => "draftresponse",
        "draft-resp" => "draftresponse", "draft-response" => "draftresponse",
        "respdraft" => "draftresponse", "responsedraft" => "draftresponse",
        "resp-draft" => "draftresponse", "response-draft" => "draftresponse",
        "anycmt" => "anycmt", "anycomment" => "anycmt",
        "tag" => "tag",
        "notag" => "notag",
        "ord" => "order", "order" => "order",
        "rord" => "rorder", "rorder" => "rorder",
        "revord" => "rorder", "revorder" => "rorder",
        "decision" => "decision", "dec" => "decision",
        "topic" => "topic",
        "option" => "option", "opt" => "option",
        "manager" => "manager", "admin" => "manager", "administrator" => "manager",
        "lead" => "lead",
        "shepherd" => "shepherd", "shep" => "shepherd",
        "conflict" => "conflict", "conf" => "conflict",
        "reconflict" => "reconflict", "reconf" => "reconflict",
        "pcconflict" => "pcconflict", "pcconf" => "pcconflict",
        "status" => "status", "has" => "has", "is" => "is",
        "rating" => "rate", "rate" => "rate",
        "revpref" => "revpref", "pref" => "revpref",
        "repref" => "revpref",
        "ss" => "ss", "search" => "ss",
        "formula" => "formula", "f" => "formula",
        "HEADING" => "HEADING", "heading" => "HEADING",
        "show" => "show", "VIEW" => "show", "view" => "show",
        "hide" => "hide", "edit" => "edit",
        "sort" => "sort", "showsort" => "showsort",
        "sortshow" => "showsort", "editsort" => "editsort",
        "sortedit" => "editsort");
    static private $_noheading_keywords = array(
        "HEADING" => "HEADING", "heading" => "HEADING",
        "show" => "show", "VIEW" => "show", "view" => "show",
        "hide" => "hide", "edit" => "edit",
        "sort" => "sort", "showsort" => "showsort",
        "sortshow" => "showsort", "editsort" => "editsort",
        "sortedit" => "editsort");
    static private $_canonical_review_keywords = array(
        "re" => 1, "cre" => 1, "ire" => 1, "pre" => 1,
        "pri" => 1, "cpri" => 1, "ipri" => 1, "ppri" => 1,
        "sec" => 1, "csec" => 1, "isec" => 1, "psec" => 1,
        "ext" => 1, "cext" => 1, "iext" => 1, "pext" => 1);


    function __construct($me, $opt) {
        global $Conf;
        if (is_string($opt))
            $opt = array("q" => $opt);

        // contact facts
        $this->contact = $me;
        $this->privChair = $me->privChair;
        $this->amPC = $me->isPC;
        $this->cid = $me->contactId;

        // paper selection
        $ptype = defval($opt, "t", "");
        if ($ptype === 0)
            $ptype = "";
        if ($this->privChair && !$ptype && $Conf->timeUpdatePaper())
            $this->limitName = "all";
        else if (($me->privChair && $ptype === "act")
                 || ($me->isPC
                     && (!$ptype || $ptype === "act" || $ptype === "all")
                     && $Conf->can_pc_see_all_submissions()))
            $this->limitName = "act";
        else if ($me->privChair && $ptype === "unm")
            $this->limitName = "unm";
        else if ($me->isPC && (!$ptype || $ptype === "s" || $ptype === "unm"))
            $this->limitName = "s";
        else if ($me->isPC && ($ptype === "und" || $ptype === "undec"))
            $this->limitName = "und";
        else if ($me->isPC && ($ptype === "acc" || $ptype === "revs"
                               || $ptype === "reqrevs" || $ptype === "req"
                               || $ptype === "lead" || $ptype === "rable"
                               || $ptype === "manager"))
            $this->limitName = $ptype;
        else if ($this->privChair && ($ptype === "all" || $ptype === "unsub"))
            $this->limitName = $ptype;
        else if ($ptype === "r" || $ptype === "rout" || $ptype === "a")
            $this->limitName = $ptype;
        else if ($ptype === "rable")
            $this->limitName = "r";
        else if (!$me->is_reviewer())
            $this->limitName = "a";
        else if (!$me->is_author())
            $this->limitName = "r";
        else
            $this->limitName = "ar";

        // track other information
        $this->allowAuthor = false;
        if ($me->privChair || $me->is_author()
            || ($this->amPC && $Conf->submission_blindness() != Conference::BLIND_ALWAYS))
            $this->allowAuthor = true;

        // default query fields
        // NB: If a complex query field, e.g., "re", "tag", or "option", is
        // default, then it must be the only default or query construction
        // will break.
        $this->fields = array();
        $qtype = defval($opt, "qt", "n");
        if ($qtype === "n" || $qtype === "ti")
            $this->fields["ti"] = 1;
        if ($qtype === "n" || $qtype === "ab")
            $this->fields["ab"] = 1;
        if ($this->allowAuthor && ($qtype === "n" || $qtype === "au" || $qtype === "ac"))
            $this->fields["au"] = 1;
        if ($this->privChair && $qtype === "ac")
            $this->fields["co"] = 1;
        if ($this->amPC && $qtype === "re")
            $this->fields["re"] = 1;
        if ($this->amPC && $qtype === "tag")
            $this->fields["tag"] = 1;
        $this->qt = ($qtype === "n" ? "" : $qtype);

        // the query itself
        $this->q = trim(defval($opt, "q", ""));

        // URL base
        if (isset($opt["urlbase"]))
            $this->urlbase = $opt["urlbase"];
        else {
            $this->urlbase = hoturl_site_relative_raw("search", "t=" . urlencode($this->limitName));
            if ($qtype !== "n")
                $this->urlbase .= "&qt=" . urlencode($qtype);
        }
        if (strpos($this->urlbase, "&amp;") !== false)
            trigger_error(caller_landmark() . " PaperSearch::urlbase should be a raw URL", E_USER_NOTICE);

        $this->_reviewer = defval($opt, "reviewer", false);
        $this->_reviewer_fixed = !!$this->_reviewer;

        $this->_allow_deleted = defval($opt, "allow_deleted", false);
    }

    // begin changing contactId to cid
    public function __get($name) {
        if ($name === "contactId") {
            trigger_error(caller_landmark() . ": PaperSearch->contactId deprecated, use cid", E_USER_NOTICE);
            return $this->cid;
        } else
            return null;
    }

    public function __set($name, $value) {
        if ($name === "contactId") {
            error_log(caller_landmark() . ": PaperSearch->contactId deprecated, use cid");
            $this->cid = $value;
        } else
            $this->$name = $value;
    }


    function warn($text) {
        $this->warnings[] = $text;
    }


    // PARSING
    // Transforms a search string into an expression object, possibly
    // including "and", "or", and "not" expressions (which point at other
    // expressions).

    static public function analyze_field_preg($reg) {
        if (is_object($reg))
            $word = $reg->value;
        else {
            $word = $reg;
            $reg = (object) array();
        }

        $word = preg_quote(preg_replace('/\s+/', " ", $word));
        if (strpos($word, "*") !== false) {
            $word = str_replace('\*', '\S*', $word);
            $word = str_replace('\\\\\S*', '\*', $word);
        }

        if (preg_match("/[\x80-\xFF]/", $word))
            $reg->preg_utf8 = Text::utf8_word_regex($word);
        else {
            $reg->preg_raw = Text::word_regex($word);
            $reg->preg_utf8 = Text::utf8_word_regex($word);
        }
        return $reg;
    }

    static public function match_field_preg($reg, $raw, $deacc) {
        if (!isset($reg->preg_raw))
            return !!preg_match('{' . $reg->preg_utf8 . '}ui', $raw);
        else if ($deacc)
            return !!preg_match('{' . $reg->preg_utf8 . '}ui', $deacc);
        else
            return !!preg_match('{' . $reg->preg_raw . '}i', $raw);
    }

    private function _searchField($word, $rtype, &$qt) {
        if (!is_array($word))
            $extra = array("regex" => array($rtype, self::analyze_field_preg($word)));
        else
            $extra = null;

        if ($this->privChair || $this->amPC)
            $qt[] = new SearchTerm($rtype, self::F_XVIEW, $word, $extra);
        else {
            $qt[] = new SearchTerm($rtype, self::F_XVIEW | self::F_REVIEWER, $word, $extra);
            $qt[] = new SearchTerm($rtype, self::F_XVIEW | self::F_AUTHOR, $word, $extra);
        }
    }

    private function _searchAuthors($word, &$qt, $keyword, $quoted) {
        $lword = strtolower($word);
        if ($keyword && !$quoted && $lword === "me")
            $this->_searchField(array($this->cid), "au_cid", $qt);
        else if ($keyword && !$quoted && $this->amPC
                 && ($lword === "pc"
                     || (($pctags = pcTags()) && isset($pctags[$lword])))) {
            $cids = self::_pcContactIdsWithTag($lword);
            $this->_searchField($cids, "au_cid", $qt);
        } else
            $this->_searchField($word, "au", $qt);
    }

    static function _matchCompar($text, $quoted) {
        $text = trim($text);
        if (($text === "any" || $text === "" || $text === "yes") && !$quoted)
            return array("", ">0");
        else if (($text === "none" || $text === "no") && !$quoted)
            return array("", "=0");
        else if (ctype_digit($text))
            return array("", "=" . $text);
        else if (preg_match('/\A(.*?)([=!<>]=?|≠|≤|≥)\s*(\d+)\z/s', $text, $m))
            return array($m[1], SearchReviewValue::canonical_comparator($m[2]) . $m[3]);
        else
            return array($text, ">0");
    }

    static function _comparTautology($m) {
        if ($m[1] === "<0")
            return "f";
        else if ($m[1] === ">=0")
            return "t";
        else
            return null;
    }

    private static function _pcContactIdsWithTag($tag) {
        if ($tag === "pc")
            return array_keys(pcMembers());
        $a = array();
        foreach (pcMembers() as $cid => $pc)
            if (stripos($pc->contactTags, " $tag ") !== false)
                $a[] = $cid;
        return $a;
    }

    private function make_contact_match($type, $text, $me_cid) {
        foreach ($this->contact_match as $i => $cm)
            if ($cm->type === $type && $cm->text === $text
                && $cm->me_cid === $me_cid)
                return $cm;
        return $this->contact_match[] = new ContactSearch($type, $text, $me_cid);
    }

    private function _reviewerMatcher($word, $quoted, $pc_only,
                                      $limited = false) {
        $type = 0;
        if ($pc_only)
            $type |= ContactSearch::F_PC;
        if ($quoted)
            $type |= ContactSearch::F_QUOTED;
        if (!$quoted && $this->amPC)
            $type |= ContactSearch::F_TAG;
        $me_cid = $this->_reviewer_fixed ? $this->reviewer_cid() : $this->cid;
        $scm = $this->make_contact_match($type, $word, $me_cid);
        if ($scm->warn_html)
            $this->warn($scm->warn_html);
        if (count($scm->ids))
            return $scm->ids;
        else
            return array(-1);
    }

    private function _one_pc_matcher($text, $quoted) {
        if (($text === "any" || $text === "" || $text === "yes") && !$quoted)
            return "!=0";
        else if (($text === "none" || $text === "no") && !$quoted)
            return "=0";
        else
            return $this->_reviewerMatcher($text, $quoted, true);
    }

    function _searchReviewer($word, $rtype, &$qt, $quoted) {
        $rt = 0;
        if (str_ends_with($rtype, "pri"))
            $rt = REVIEW_PRIMARY;
        else if (str_ends_with($rtype, "sec"))
            $rt = REVIEW_SECONDARY;
        else if (str_ends_with($rtype, "ext"))
            $rt = REVIEW_EXTERNAL;
        if (str_starts_with($rtype, "c"))
            $rt |= self::F_COMPLETE;
        if (str_starts_with($rtype, "i"))
            $rt |= self::F_INCOMPLETE;
        if (str_starts_with($rtype, "p") && $rtype !== "pri")
            $rt |= self::F_INPROGRESS;

        $m = self::_matchCompar($word, $quoted);
        if (($type = self::_comparTautology($m))) {
            $qt[] = new SearchTerm($type);
            return;
        }

        if ($m[0] === "")
            $contacts = null;
        else if (($rt & self::F_REVIEWTYPEMASK) >= REVIEW_PC)
            $contacts = $this->_reviewerMatcher($m[0], $quoted, true);
        else
            $contacts = $this->_reviewerMatcher($m[0], $quoted, false);
        $value = new SearchReviewValue($m[1], $contacts);
        $qt[] = new SearchTerm("re", $rt | self::F_XVIEW, $value);
    }

    function _search_decision($word, &$qt, $quoted, $allow_status) {
        global $Conf;
        if (!$quoted && strcasecmp($word, "yes") == 0)
            $value = ">0";
        else if (!$quoted && strcasecmp($word, "no") == 0)
            $value = "<0";
        else if ($word === "?"
                 || (!$quoted && strcasecmp($word, "none") == 0)
                 || (!$quoted && strcasecmp($word, "unknown") == 0))
            $value = "=0";
        else if (!$quoted && strcasecmp($word, "any") == 0)
            $value = "!=0";
        else {
            $value = matchValue($Conf->decision_map(), $word, true);
            if (count($value) == 0) {
                $this->warn("“" . htmlspecialchars($word) . "” doesn’t match a " . ($allow_status ? "decision or status." : "decision."));
                $value[] = -10000000;
            }
            $value = sql_in_numeric_set($value);
        }

        $value = array("outcome", $value);
        if ($this->amPC && $Conf->timePCViewDecision(true))
            $qt[] = new SearchTerm("pf", 0, $value);
        else
            $qt[] = new SearchTerm("pf", self::F_XVIEW, $value);
    }

    private function _search_conflict($word, &$qt, $quoted, $pc_only) {
        $m = self::_matchCompar($word, $quoted);
        if (($type = self::_comparTautology($m))) {
            $qt[] = new SearchTerm($type);
            return;
        }

        $contacts = $this->_reviewerMatcher($m[0], $quoted, $pc_only);
        $value = new SearchReviewValue($m[1], $contacts);
        if ($this->privChair
            || (is_array($contacts) && count($contacts) == 1 && $contacts[0] == $this->cid))
            $qt[] = new SearchTerm("conflict", 0, $value);
        else {
            $qt[] = new SearchTerm("conflict", self::F_XVIEW, $value);
            if (($newvalue = $value->restrictContact($this->cid)))
                $qt[] = new SearchTerm("conflict", 0, $newvalue);
        }
    }

    private function _searchReviewerConflict($word, &$qt, $quoted) {
        $args = array();
        while (preg_match('/\A\s*#?(\d+)(?:-#?(\d+))?\s*,?\s*(.*)\z/s', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            foreach (range($m[1], $m[2]) as $p)
                $args[$p] = true;
            $word = $m[3];
        }
        if ($word !== "" || count($args) == 0) {
            $this->warn("The <code>reconflict</code> keyword expects a list of paper numbers.");
            $qt[] = new SearchTerm("f");
        } else {
            $result = Dbl::qe("select distinct contactId from PaperReview where paperId in (" . join(", ", array_keys($args)) . ")");
            $contacts = Dbl::fetch_first_columns($result);
            $qt[] = new SearchTerm("conflict", 0, new SearchReviewValue(">0", $contacts));
        }
    }

    private function _search_comment_tag($rt, $tag, $rvalue, $round, &$qt) {
        $value = new SearchReviewValue($rvalue, $tag !== "none" ? $tag : "any");
        $term = new SearchTerm("cmttag", $rt, $value);
        if ($round !== null)
            $term->commentRound = $round;
        if ($tag === "none")
            $term = SearchTerm::combine("not", $term);
        $qt[] = $term;
    }

    private function _search_comment($word, $ctype, &$qt, $quoted) {
        global $Conf;
        $m = self::_matchCompar($word, $quoted);
        if (($type = self::_comparTautology($m))) {
            $qt[] = new SearchTerm($type);
            return;
        }

        // canonicalize comment type
        $ctype = strtolower($ctype);
        if (str_ends_with($ctype, "resp"))
            $ctype .= "onse";
        if (str_ends_with($ctype, "-draft"))
            $ctype = "draft" . substr($ctype, 0, strlen($ctype) - 6);
        else if (str_ends_with($ctype, "draft"))
            $ctype = "draft" . substr($ctype, 0, strlen($ctype) - 5);
        if (str_starts_with($ctype, "draft-"))
            $ctype = "draft" . substr($ctype, 6);

        $rt = 0;
        $round = null;
        if (str_starts_with($ctype, "draft") && str_ends_with($ctype, "response")) {
            $rt |= self::F_REQUIREDRAFT | self::F_ALLOWDRAFT;
            $ctype = substr($ctype, 5);
        }
        if ($ctype === "response" || $ctype === "anycmt")
            $rt |= self::F_ALLOWRESPONSE;
        else if (str_ends_with($ctype, "response")) {
            $rname = substr($ctype, 0, strlen($ctype) - 8);
            $round = $Conf->resp_round_number($rname);
            if ($round === false) {
                $this->warn("No such response round “" . htmlspecialchars($ctype) . "”.");
                $qt[] = new SearchTerm("f");
                return;
            }
            $rt |= self::F_ALLOWRESPONSE;
        }
        if ($ctype === "cmt" || $ctype === "aucmt" || $ctype === "anycmt")
            $rt |= self::F_ALLOWCOMMENT;
        if ($ctype === "aucmt")
            $rt |= self::F_AUTHORCOMMENT;
        if (substr($m[0], 0, 1) === "#") {
            $rt |= ($this->privChair ? 0 : self::F_NONCONFLICT) | self::F_XVIEW;
            $tags = $this->_expand_tag(substr($m[0], 1), false);
            foreach ($tags as $tag)
                $this->_search_comment_tag($rt, $tag, $m[1], $round, $qt);
            if (!count($tags)) {
                $qt[] = new SearchTerm("f");
                return;
            } else if (count($tags) !== 1 || $tags[0] === "none" || $tags[0] === "any"
                       || !pcTags($tags[0]))
                return;
        }
        $contacts = ($m[0] === "" ? null : $contacts = $this->_reviewerMatcher($m[0], $quoted, false));
        $value = new SearchReviewValue($m[1], $contacts);
        $term = new SearchTerm("cmt", $rt | self::F_XVIEW, $value);
        if ($round !== null)
            $term->commentRound = $round;
        $qt[] = $term;
    }

    function _searchReviews($word, $f, &$qt, $quoted, $noswitch = false) {
        global $Opt;
        $countexpr = ">0";
        $contacts = null;
        $contactword = "";
        $field = $f->id;

        if (preg_match('/\A(.+?[^:=<>!])([:=<>!]|≠|≤|≥)(.*)\z/s', $word, $m)
            && !ctype_digit($m[1])) {
            $contacts = $this->_reviewerMatcher($m[1], $quoted, false);
            $word = ($m[2] === ":" ? $m[3] : $m[2] . $m[3]);
            $contactword = $m[1] . ":";
        }

        if ($f->has_options) {
            if ($word === "any")
                $value = "$field>0";
            else if ($word === "none")
                $value = "$field=0";
            else if (preg_match('/\A(\d*?)([=!<>]=?|≠|≤|≥)?\s*([A-Za-z]|\d+)\z/s', $word, $m)) {
                if ($m[1] === "")
                    $m[1] = 1;
                $m[2] = SearchReviewValue::canonical_comparator($m[2]);
                if ($f->option_letter != (ctype_digit($m[3]) == false))
                    $value = "$field=-1"; // XXX
                else {
                    $score = $m[3];
                    if ($f->option_letter) {
                        if (!defval($Opt, "smartScoreCompare") || $noswitch) {
                            // switch meaning of inequality
                            if ($m[2][0] === "<")
                                $m[2] = ">" . substr($m[2], 1);
                            else if ($m[2][0] === ">")
                                $m[2] = "<" . substr($m[2], 1);
                        }
                        $score = strtoupper($score);
                        $m[3] = $f->option_letter - ord($score);
                    }
                    if (($m[3] < 1 && ($m[2][0] === "<" || $m[2] === "="))
                        || ($m[3] == 1 && $m[2] === "<")
                        || ($m[3] == count($f->options) && $m[2] === ">")
                        || ($m[3] > count($f->options) && ($m[2][0] === ">" || $m[2] === "="))) {
                        if ($f->option_letter)
                            $warnings = array("<" => "worse than", ">" => "better than");
                        else
                            $warnings = array("<" => "less than", ">" => "greater than");
                        $t = new SearchTerm("f");
                        $t->set("contradiction_warning", "No $f->name_html scores are " . ($m[2] === "=" ? "" : $warnings[$m[2][0]] . (strlen($m[2]) == 1 ? " " : " or equal to ")) . $score . ".");
                        $qt[] = $t;
                        return false;
                    } else {
                        $countexpr = (int) $m[1] ? ">=" . $m[1] : "=0";
                        $value = $field . $m[2] . $m[3];
                    }
                }
            } else if ($f->option_letter
                       ? preg_match('/\A\s*([A-Za-z])\s*(-?|\.\.\.?)\s*([A-Za-z])\s*\z/s', $word, $m)
                       : preg_match('/\A\s*(\d+)\s*(-|\.\.\.?)\s*(\d+)\s*\z/s', $word, $m)) {
                $qo = array();
                if ($m[2] === "-" || $m[2] === "") {
                    $this->_searchReviews($contactword . $m[1], $f, $qo, $quoted);
                    $this->_searchReviews($contactword . $m[3], $f, $qo, $quoted);
                } else
                    $this->_searchReviews($contactword . ">=" . $m[1], $f, $qo, $quoted, true);
                if ($this->_searchReviews($contactword . "<" . $m[1], $f, $qo, $quoted, true))
                    $qo[count($qo) - 1] = SearchTerm::negate($qo[count($qo) - 1]);
                else
                    array_pop($qo);
                if ($this->_searchReviews($contactword . ">" . $m[3], $f, $qo, $quoted, true))
                    $qo[count($qo) - 1] = SearchTerm::negate($qo[count($qo) - 1]);
                else
                    array_pop($qo);
                $qt[] = new SearchTerm("and", 0, $qo);
                return true;
            } else              // XXX
                $value = "$field=-1";
        } else {
            if ($word === "any")
                $value = "$field!=''";
            else if ($word === "none")
                $value = "$field=''";
            else
                $value = "$field like '%" . sqlq_for_like($word) . "%'";
        }

        $value = new SearchReviewValue($countexpr, $contacts, $value, $f->view_score);
        $qt[] = new SearchTerm("re", self::F_COMPLETE | self::F_XVIEW, $value);
        return true;
    }

    private function _search_revpref($word, &$qt, $quoted) {
        $contacts = null;
        if (preg_match('/\A(.*?[^:=<>!])([:=!<>]|≠|≤|≥)(.*)\z/s', $word, $m)
            && !ctype_digit($m[1])) {
            $contacts = $this->_reviewerMatcher($m[1], $quoted, true,
                                                !$this->privChair);
            $word = ($m[2] === ":" ? $m[3] : $m[2] . $m[3]);
        }

        if (!preg_match(',\A(\d*)\s*([=!<>]=?|≠|≤|≥|)\s*(-?\d*)\s*([xyz]?)\z,i', $word, $m)
            || ($m[1] === "" && $m[3] === "" && $m[4] === "")) {
            $qt[] = new SearchTerm("f");
            return;
        }

        if ($m[1] === "")
            $m[1] = "1";
        else if ($m[2] === "")
            list($m[1], $m[3]) = array("1", $m[1]);
        $mx = array((int) $m[1] ? ">=" . $m[1] : "=0");
        $compar = SearchReviewValue::canonical_comparator($m[2]);
        if ($m[3] !== "")
            $mx[] = "preference" . $compar . $m[3];
        if ($m[4] !== "")
            $mx[] = "expertise" . $compar . (121 - ord(strtolower($m[4])));

        // since 0 preferences are not stored, we must negate the sense of the
        // comparison if a preference of 0 might match
        $scratch = new SearchReviewValue($mx[1]);
        if ($scratch->test(0))
            foreach ($mx as &$mxv)
                $mxv = SearchReviewValue::negate_countexpr($mxv);

        // PC members can only search their own preferences; we enforce
        // this restriction below in clauseTermSetRevpref.
        $value = new SearchReviewValue($mx[0], $contacts, join(" and ", array_slice($mx, 1)));
        $qt[] = new SearchTerm("revpref", $this->privChair ? 0 : self::F_NONCONFLICT, $value);
    }

    private function _search_formula($word, &$qt, $quoted) {
        if (preg_match('/\A[^(){}\[\]]+\z/', $word) && !$quoted
            && ($result = Dbl::qe("select * from Formula where name=?", $word))
            && ($row = $result->fetch_object())) {
            $formula = new Formula($row);
            Dbl::free($result);
        } else
            $formula = new Formula($word);
        if ($formula->check())
            $qt[] = new SearchTerm("formula", self::F_XVIEW, $formula);
        else {
            $this->warn($formula->error_html());
            $qt[] = new SearchTerm("f");
        }
    }

    private function _expand_tag($tagword, $allow_star) {
        // see also TagAssigner
        $ret = array("");
        $twiddle = strpos($tagword, "~");
        if ($this->privChair && $twiddle > 0 && !ctype_digit(substr($tagword, 0, $twiddle))) {
            $ret = ContactSearch::make_pc(substr($tagword, 0, $twiddle), $this->cid)->ids;
            if (count($ret) == 0)
                $this->warn("“" . htmlspecialchars($c) . "” doesn’t match a PC email.");
            $tagword = substr($tagword, $twiddle);
        } else if ($twiddle === 0 && @$tagword[1] !== "~")
            $ret[0] = $this->cid;

        $tagger = new Tagger($this->contact);
        if (!$tagger->check("#" . $tagword, Tagger::ALLOWRESERVED | Tagger::NOVALUE | ($allow_star ? Tagger::ALLOWSTAR : 0))) {
            $this->warn($tagger->error_html);
            $ret = array();
        }
        foreach ($ret as &$x)
            $x .= $tagword;
        return $ret;
    }

    private function _search_one_tag($value, $old_arg) {
        if (($starpos = strpos($value, "*")) !== false) {
            $arg = "(\3 like '" . str_replace("*", "%", sqlq_for_like($value)) . "'";
            if ($starpos == 0)
                $arg .= " and \3 not like '%~%'";
            $arg .= ")";
        } else if ($value === "any" || $value === "none")
            $arg = "(\3 is not null and (\3 not like '%~%' or \3 like '{$this->cid}~%'" . ($this->privChair ? " or \3 like '~~%'" : "") . "))";
        else
            $arg = "\3='" . sqlq($value) . "'";
        return $old_arg ? "$old_arg or $arg" : $arg;
    }

    private function _search_tags($word, $keyword, &$qt) {
        global $Conf;
        if ($word[0] === "#")
            $word = substr($word, 1);

        // allow external reviewers to search their own rank tag
        if (!$this->amPC) {
            $ranktag = "~" . $Conf->setting_data("tag_rank");
            if (!$Conf->setting("tag_rank")
                || substr($word, 0, strlen($ranktag)) !== $ranktag
                || (strlen($word) > strlen($ranktag)
                    && $word[strlen($ranktag)] !== "#"))
                return;
        }

        if (preg_match('/\A([^#=!<>\x80-\xFF]+)(?:#|=)(-?\d+)(?:\.\.\.?|-)(-?\d+)\z/', $word, $m)) {
            $tagword = $m[1];
            $compar = array(null, ">=" . $m[2], "<=" . $m[3]);
        } else if (preg_match('/\A([^#=!<>\x80-\xFF]+)(#?)([=!<>]=?|≠|≤|≥|)(-?\d+)\z/', $word, $m)
            && $m[1] !== "any" && $m[1] !== "none"
            && ($m[2] !== "" || $m[3] !== "")) {
            $tagword = $m[1];
            $compar = array(null, SearchReviewValue::canonical_comparator($m[3]) . $m[4]);
        } else {
            $tagword = $word;
            $compar = array(null);
        }

        $negated = false;
        if (substr($tagword, 0, 1) === "-" && $keyword === "tag") {
            $negated = true;
            $tagword = ltrim(substr($tagword, 1));
        }

        $tags = $this->_expand_tag($tagword, $keyword === "tag");
        if (!count($tags))
            return new SearchTerm("f");

        foreach ($tags as $tag)
            $compar[0] = $this->_search_one_tag($tag, $compar[0]);
        $extra = null;
        if ($keyword === "order" || $keyword === "rorder" || !$keyword)
            $extra = array("tagorder" => (object) array("tag" => $tags[0], "reverse" => $keyword === "rorder"));
        $term = new SearchTerm("tag", self::F_XVIEW, $compar, $extra);
        if ($tags[0] === "none")
            $term = SearchTerm::negate($term);
        $qt[] = $term;
    }

    static public function analyze_option_search($word) {
        if (preg_match('/\A(.*?)([:#](?:[=!<>]=?|≠|≤|≥|)|[=!<>]=?|≠|≤|≥)(.*)\z/', $word, $m)) {
            $oname = $m[1];
            if ($m[2][0] === ":" || $m[2][0] === "#")
                $m[2] = substr($m[2], 1);
            $ocompar = SearchReviewValue::canonical_comparator($m[2]);
            $oval = strtolower(simplify_whitespace($m[3]));
        } else {
            $oname = $word;
            $ocompar = "=";
            $oval = "";
        }
        $oname = strtolower(simplify_whitespace($oname));

        // match all options
        $qo = $warn = array();
        $option_failure = false;
        if ($oname === "none" || $oname === "any")
            $omatches = PaperOption::option_list();
        else
            $omatches = PaperOption::search($oname);
        // global $Conf; $Conf->infoMsg(Ht::pre_text(var_export($omatches, true)));
        if (count($omatches)) {
            foreach ($omatches as $oid => $o) {
                // find the relevant values
                if ($o->type === "numeric") {
                    if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m))
                        $qo[] = array($o, $ocompar, $m[1]);
                    else if ($oval === "" || $oval === "yes")
                        $qo[] = array($o, "!=", 0, $oval);
                    else if ($oval === "no")
                        $qo[] = array($o, "=", 0);
                    else
                        $warn[] = "Submission option “" . htmlspecialchars($o->name) . "” takes integer values.";
                } else if ($o->has_selector()) {
                    $xval = array();
                    if ($oval === "") {
                        foreach ($o->selector as $k => $v)
                            if (strcasecmp($v, "yes") == 0)
                                $xval[] = $k;
                        if (count($xval) == 0)
                            $xval = array_keys($o->selector);
                    } else
                        $xval = matchValue($o->selector, $oval);
                    if (count($xval) == 0)
                        $warn[] = "“" . htmlspecialchars($oval) . "” doesn’t match any opt:" . htmlspecialchars($oname) . " values.";
                    else if (count($xval) == 1)
                        $qo[] = array($o, $ocompar, $xval[0], $oval);
                    else if ($ocompar !== "=" && $ocompar !== "!=")
                        $warn[] = "Submission option “" . htmlspecialchars("$oname:$oval") . "” matches multiple values, can’t use " . htmlspecialchars($ocompar) . ".";
                    else
                        $qo[] = array($o, $ocompar === "=" ? "in" : "not in", $xval, $oval);
                } else {
                    if ($oval === "" || $oval === "yes")
                        $qo[] = array($o, "!=", 0, $oval);
                    else if ($oval === "no")
                        $qo[] = array($o, "=", 0);
                    else
                        continue;
                }
            }
        } else if (($ocompar === "=" || $ocompar === "!=") && $oval === "")
            foreach (PaperOption::option_list() as $oid => $o)
                if ($o->has_selector()) {
                    foreach (matchValue($o->selector, $oname) as $xval)
                        $qo[] = array($o, $ocompar, $xval);
                }

        return (object) array("os" => $qo, "warn" => $warn, "negate" => $oname === "none");
    }

    function _search_options($word, &$qt, $report_error) {
        $os = self::analyze_option_search($word);
        foreach ($os->warn as $w)
            $this->warn($w);
        if (!count($os->os)) {
            if ($report_error && !count($os->warn))
                $this->warn("“" . htmlspecialchars($word) . "” doesn’t match a submission option.");
            if ($report_error || count($os->warn))
                $qt[] = new SearchTerm("f");
            return false;
        }

        // add expressions
        $qz = array();
        foreach ($os->os as $o) {
            $cmp = ctype_alpha($o[1][0]) ? " $o[1] " : $o[1];
            $value = is_array($o[2]) ? "(" . join(",", $o[2]) . ")" : $o[2];
            $qz[] = new SearchTerm("option", self::F_XVIEW, array($o[0], $cmp . $value));
        }
        if ($os->negate)
            $qz = array(SearchTerm::negate(SearchTerm::combine("or", $qz)));
        $qt = array_merge($qt, $qz);
        return true;
    }

    private function _search_has($word, &$qt, $quoted) {
        global $Conf;
        $lword = strtolower($word);
        $word = @self::$_keywords[$lword] ? : $word;
        if (strcasecmp($word, "paper") == 0 || strcasecmp($word, "submission") == 0)
            $qt[] = new SearchTerm("pf", 0, array("paperStorageId", "!=0"));
        else if (strcasecmp($word, "final") == 0 || strcasecmp($word, "finalcopy") == 0)
            $qt[] = new SearchTerm("pf", 0, array("finalPaperStorageId", "!=0"));
        else if (strcasecmp($word, "abstract") == 0)
            $qt[] = new SearchTerm("pf", 0, array("abstract", "!=''"));
        else if (preg_match('/\A(?:(?:draft-?)?\w*resp(?:onse)?|\w*resp(?:onse)(?:-?draft)?|cmt|aucmt|anycmt)\z/i', $word))
            $this->_search_comment(">0", $word, $qt, $quoted);
        else if (strcasecmp($word, "manager") == 0 || strcasecmp($word, "admin") == 0 || strcasecmp($word, "administrator") == 0)
            $qt[] = new SearchTerm("pf", 0, array("managerContactId", "!=0"));
        else if (preg_match('/\A[ci]?(?:re|pri|sec|ext)\z/', $word))
            $this->_searchReviewer(">0", $word, $qt, $quoted);
        else if (strcasecmp($word, "lead") == 0)
            $qt[] = new SearchTerm("pf", self::F_XVIEW, array("leadContactId", "!=0"));
        else if (strcasecmp($word, "shep") == 0 || strcasecmp($word, "shepherd") == 0)
            $qt[] = new SearchTerm("pf", self::F_XVIEW, array("shepherdContactId", "!=0"));
        else if (preg_match('/\A\w+\z/', $word) && $this->_search_options("$word:yes", $qt, false))
            /* OK */;
        else {
            $x = array("“paper”", "“final”", "“abstract”", "“comment”", "“aucomment”", "“pcrev”", "“extrev”");
            foreach ($Conf->resp_round_list() as $i => $rname) {
                if (!in_array("“response”", $x))
                    array_push($x, "“response”", "“draftresponse”");
                if ($i)
                    $x[] = "“{$rname}response”";
            }
            $this->warn("Unknown “has:” search. I understand " . commajoin($x) . ".");
            $qt[] = new SearchTerm("f");
        }
    }

    private function _searchReviewRatings($word, &$qt) {
        global $Conf;
        $this->reviewAdjust = true;
        if (preg_match('/\A(.+?)\s*(|[=!<>]=?|≠|≤|≥)\s*(\d*)\z/', $word, $m)
            && ($m[3] !== "" || $m[2] === "")
            && $Conf->setting("rev_ratings") != REV_RATINGS_NONE) {
            // adjust counts
            if ($m[3] === "") {
                $m[2] = ">";
                $m[3] = "0";
            }
            if ($m[2] === "")
                $m[2] = ($m[3] == 0 ? "=" : ">=");
            else
                $m[2] = SearchReviewValue::canonical_comparator($m[2]);
            $nqt = count($qt);

            // resolve rating type
            if ($m[1] === "+" || $m[1] === "good") {
                $this->interestingRatings["good"] = ">0";
                $term = "nrate_good";
            } else if ($m[1] === "-" || $m[1] === "bad"
                       || $m[1] === "\xE2\x88\x92" /* unicode MINUS */) {
                $this->interestingRatings["bad"] = "<1";
                $term = "nrate_bad";
            } else if ($m[1] === "any") {
                $this->interestingRatings["any"] = "!=100";
                $term = "nrate_any";
            } else {
                $x = array_diff(matchValue(ReviewForm::$rating_types, $m[1]),
                                array("n")); /* don't allow "average" */
                if (count($x) == 0) {
                    $this->warn("Unknown rating type “" . htmlspecialchars($m[1]) . "”.");
                    $qt[] = new SearchTerm("f");
                } else {
                    $type = count($this->interestingRatings);
                    $this->interestingRatings[$type] = " in (" . join(",", $x) . ")";
                    $term = "nrate_$type";
                }
            }

            if (count($qt) == $nqt) {
                if ($m[2][0] === "<" || $m[2] === "!="
                    || ($m[2] === "=" && $m[3] == 0)
                    || ($m[2] === ">=" && $m[3] == 0))
                    $term = "coalesce($term,0)";
                $qt[] = new SearchTerm("revadj", 0, array("rate" => $term . $m[2] . $m[3]));
            }
        } else {
            if ($Conf->setting("rev_ratings") == REV_RATINGS_NONE)
                $this->warn("Review ratings are disabled.");
            else
                $this->warn("Bad review rating query “" . htmlspecialchars($word) . "”.");
            $qt[] = new SearchTerm("f");
        }
    }

    static private function find_end_balanced_parens($str) {
        $pcount = $quote = 0;
        for ($pos = 0; $pos < strlen($str)
                 && (!ctype_space($str[$pos]) || $pcount || $quote); ++$pos) {
            $ch = $str[$pos];
            if ($quote) {
                if ($ch === "\\" && $pos + 1 < strlen($str))
                    ++$pos;
                else if ($ch === "\"")
                    $quote = 0;
            } else if ($ch === "\"")
                $quote = 1;
            else if ($ch === "(" || $ch === "[" || $ch === "{")
                ++$pcount;
            else if ($ch === ")" || $ch === "]" || $ch === "}") {
                if (!$pcount)
                    break;
                --$pcount;
            }
        }
        return $pos;
    }

    static public function parse_sorter($text) {
        if (!self::$_sort_keywords)
            self::$_sort_keywords =
                array("by" => "by", "up" => "up", "down" => "down",
                      "reverse" => "down", "reversed" => "down",
                      "count" => "C", "counts" => "C", "av" => "A",
                      "ave" => "A", "average" => "A", "med" => "E",
                      "median" => "E", "var" => "V", "variance" => "V",
                      "max-min" => "D", "my" => "Y", "score" => "");

        $text = simplify_whitespace($text);
        $sort = (object) array("type" => null, "field" => null, "reverse" => null,
                               "score" => null, "empty" => $text === "");
        if (($ch1 = substr($text, 0, 1)) === "-" || $ch1 === "+") {
            $sort->reverse = $ch1 === "-";
            $text = ltrim(substr($text, 1));
        }

        // separate text into words
        $words = array();
        $bypos = false;
        while ($text !== "") {
            preg_match(',\A([^\s\(]*)(.*)\z,s', $text, $m);
            if (substr($m[2], 0, 1) === "(") {
                $pos = self::find_end_balanced_parens($m[2]);
                $m[1] .= substr($m[2], 0, $pos);
                $m[2] = substr($m[2], $pos);
            }
            $words[] = $m[1];
            $text = ltrim($m[2]);
            if ($m[1] === "by" && $bypos === false)
                $bypos = count($words) - 1;
        }

        // go over words
        $next_words = array();
        for ($i = 0; $i != count($words); ++$i) {
            $w = $words[$i];
            if (($bypos === false || $i > $bypos)
                && isset(self::$_sort_keywords[$w])) {
                $x = self::$_sort_keywords[$w];
                if ($x === "up")
                    $sort->reverse = false;
                else if ($x === "down")
                    $sort->reverse = true;
                else if (ctype_upper($x))
                    $sort->score = $x;
            } else if ($bypos === false || $i < $bypos)
                $next_words[] = $w;
        }

        if (count($next_words))
            $sort->type = join(" ", $next_words);
        return $sort;
    }

    public static function combine_sorters($a, $b) {
        foreach (array("type", "reverse", "score", "field") as $k)
            if ($a->$k === null)
                $a->$k = $b->$k;
    }

    private static function _expand_saved_search($word, $recursion) {
        global $Conf;
        if (isset($recursion[$word]))
            return false;
        $t = $Conf->setting_data("ss:" . $word, "");
        $search = json_decode($t);
        if ($search && is_object($search) && isset($search->q))
            return $search->q;
        else
            return null;
    }

    function _searchQueryWord($word, $report_error) {
        global $Conf;

        // check for paper number or "#TAG"
        if (preg_match('/\A#?(\d+)(?:-#?(\d+))?\z/', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            return new SearchTerm("pn", 0, array(range($m[1], $m[2]), array()));
        } else if (substr($word, 0, 1) === "#") {
            $qe = $this->_searchQueryWord("tag:" . $word, false);
            if (!$qe->isfalse())
                return $qe;
        }

        // Allow searches like "ovemer>2"; parse as "ovemer:>2".
        if (preg_match('/\A([-_A-Za-z0-9]+)((?:[=!<>]=?|≠|≤|≥)[^:]+)\z/', $word, $m)) {
            $qe = $this->_searchQueryWord($m[1] . ":" . $m[2], false);
            if (!$qe->isfalse())
                return $qe;
        }

        $keyword = null;
        if (($colon = strpos($word, ":")) > 0) {
            $x = substr($word, 0, $colon);
            if (strpos($x, '"') === false) {
                $keyword = @self::$_keywords[$x] ? : $x;
                $word = substr($word, $colon + 1);
                if ($word === false)
                    $word = "";
            }
        }

        // Treat unquoted "*", "ANY", and "ALL" as special; return true.
        if ($word === "*" || $word === "ANY" || $word === "ALL" || $word === "")
            return new SearchTerm("t");
        else if ($word === "NONE")
            return new SearchTerm("f");

        $quoted = ($word[0] === '"');
        $negated = false;
        if ($quoted)
            $word = str_replace(array('"', '*'), array('', '\*'), $word);
        if ($keyword === "notag") {
            $keyword = "tag";
            $negated = true;
        }

        $qt = array();
        if ($keyword ? $keyword === "ti" : isset($this->fields["ti"]))
            $this->_searchField($word, "ti", $qt);
        if ($keyword ? $keyword === "ab" : isset($this->fields["ab"]))
            $this->_searchField($word, "ab", $qt);
        if ($keyword ? $keyword === "au" : isset($this->fields["au"]))
            $this->_searchAuthors($word, $qt, $keyword, $quoted);
        if ($keyword ? $keyword === "co" : isset($this->fields["co"]))
            $this->_searchField($word, "co", $qt);
        if ($keyword ? $keyword === "re" : isset($this->fields["re"]))
            $this->_searchReviewer($word, "re", $qt, $quoted);
        else if ($keyword && @self::$_canonical_review_keywords[$keyword])
            $this->_searchReviewer($word, $keyword, $qt, $quoted);
        if (preg_match('/\A(?:(?:draft-?)?\w*resp(?:onse)|\w*resp(?:onse)?(-?draft)?|cmt|aucmt|anycmt)\z/', $keyword))
            $this->_search_comment($word, $keyword, $qt, $quoted);
        if ($keyword === "revpref" && $this->amPC)
            $this->_search_revpref($word, $qt, $quoted);
        foreach (array("lead", "shepherd", "manager") as $ctype)
            if ($keyword === $ctype) {
                $x = $this->_one_pc_matcher($word, $quoted);
                $qt[] = new SearchTerm("pf", self::F_XVIEW, array("${ctype}ContactId", $x));
                if ($ctype === "manager" && $word === "me" && !$quoted && $this->privChair)
                    $qt[] = new SearchTerm("pf", self::F_XVIEW, array("${ctype}ContactId", "=0"));
            }
        if (($keyword ? $keyword === "tag" : isset($this->fields["tag"]))
            || $keyword === "order" || $keyword === "rorder")
            $this->_search_tags($word, $keyword, $qt);
        if ($keyword === "topic") {
            $type = "topic";
            $value = null;
            if ($word === "none" || $word === "any")
                $value = $word;
            else {
                $x = strtolower(simplify_whitespace($word));
                $tids = array();
                foreach ($Conf->topic_map() as $tid => $tname)
                    if (strstr(strtolower($tname), $x) !== false)
                        $tids[] = $tid;
                if (count($tids) == 0 && $word !== "none" && $word !== "any") {
                    $this->warn("“" . htmlspecialchars($x) . "” does not match any defined paper topic.");
                    $type = "f";
                } else
                    $value = $tids;
            }
            $qt[] = new SearchTerm($type, self::F_XVIEW, $value);
        }
        if ($keyword === "option")
            $this->_search_options($word, $qt, true);
        if ($keyword === "status" || $keyword === "is") {
            if (strcasecmp($word, "withdrawn") == 0 || strcasecmp($word, "withdraw") == 0 || strcasecmp($word, "with") == 0)
                $qt[] = new SearchTerm("pf", 0, array("timeWithdrawn", ">0"));
            else if (strcasecmp($word, "submitted") == 0 || strcasecmp($word, "submit") == 0 || strcasecmp($word, "sub") == 0)
                $qt[] = new SearchTerm("pf", 0, array("timeSubmitted", ">0"));
            else if (strcasecmp($word, "unsubmitted") == 0 || strcasecmp($word, "unsubmit") == 0 || strcasecmp($word, "unsub") == 0)
                $qt[] = new SearchTerm("pf", 0, array("timeSubmitted", "<=0", "timeWithdrawn", "<=0"));
            else if (strcasecmp($word, "active") == 0)
                $qt[] = new SearchTerm("pf", 0, array("timeWithdrawn", "<=0"));
            else
                $this->_search_decision($word, $qt, $quoted, true);
        }
        if ($keyword === "decision")
            $this->_search_decision($word, $qt, $quoted, false);
        if ($keyword === "conflict" && $this->amPC)
            $this->_search_conflict($word, $qt, $quoted, false);
        if ($keyword === "pcconflict" && $this->amPC)
            $this->_search_conflict($word, $qt, $quoted, true);
        if ($keyword === "reconflict" && $this->privChair)
            $this->_searchReviewerConflict($word, $qt, $quoted);
        if ($keyword === "round" && $this->amPC) {
            $this->reviewAdjust = true;
            if ($word === "none")
                $qt[] = new SearchTerm("revadj", 0, array("round" => array(0)));
            else if ($word === "any")
                $qt[] = new SearchTerm("revadj", 0, array("round" => range(1, count($Conf->round_list()) - 1)));
            else {
                $x = simplify_whitespace($word);
                $rounds = matchValue($Conf->round_list(), $x);
                if (count($rounds) == 0) {
                    $this->warn("“" . htmlspecialchars($x) . "” doesn’t match a review round.");
                    $qt[] = new SearchTerm("f");
                } else
                    $qt[] = new SearchTerm("revadj", 0, array("round" => $rounds));
            }
        }
        if ($keyword === "rate")
            $this->_searchReviewRatings($word, $qt);
        if ($keyword === "has")
            $this->_search_has($word, $qt, $quoted);
        if ($keyword === "formula")
            $this->_search_formula($word, $qt, $quoted);
        if ($keyword === "ss") {
            if (($nextq = self::_expand_saved_search($word, $this->_ssRecursion))) {
                $this->_ssRecursion[$word] = true;
                $qe = $this->_searchQueryType($nextq);
                unset($this->_ssRecursion[$word]);
            } else
                $qe = null;
            if (!$qe && $nextq === false)
                $this->warn("Saved search “" . htmlspecialchars($word) . "” is incorrectly defined in terms of itself.");
            else if (!$qe && !$Conf->setting_data("ss:$word"))
                $this->warn("There is no “" . htmlspecialchars($word) . "” saved search.");
            else if (!$qe)
                $this->warn("The “" . htmlspecialchars($word) . "” saved search is defined incorrectly.");
            $qt[] = ($qe ? : new SearchTerm("f"));
        }
        if ($keyword === "HEADING") {
            if (($heading = simplify_whitespace($word)) !== "")
                $this->headingmap = array();
            $qt[] = SearchTerm::make_float(array("heading" => $heading));
        }
        if ($keyword === "show" || $keyword === "hide" || $keyword === "edit"
            || $keyword === "sort" || $keyword === "showsort"
            || $keyword === "editsort") {
            $editing = strpos($keyword, "edit") !== false;
            $sorting = strpos($keyword, "sort") !== false;
            $views = array();
            $a = ($keyword === "hide" ? false : ($editing ? "edit" : true));
            $word = simplify_whitespace($word);
            $ch1 = substr($word, 0, 1);
            if ($ch1 === "-" && !$sorting)
                list($a, $word) = array(false, substr($word, 1));
            $wtype = $word;
            if ($sorting) {
                $sort = self::parse_sorter($wtype);
                $wtype = $sort->type;
            }
            if ($wtype !== "" && $keyword !== "sort")
                $views[$wtype] = $a;
            $f = array("view" => $views);
            if ($sorting)
                $f["sort"] = array($word);
            $qt[] = SearchTerm::make_float($f);
        }

        // Finally, look for a review field.
        if ($keyword && !isset(self::$_keywords[$keyword]) && count($qt) == 0) {
            if (($field = ReviewForm::field_search($keyword)))
                $this->_searchReviews($word, $field, $qt, $quoted);
            else if (!$this->_search_options("$keyword:$word", $qt, false)
                     && $report_error)
                $this->warn("Unrecognized keyword “" . htmlspecialchars($keyword) . "”.");
        }

        // Must always return something
        if (count($qt) == 0)
            $qt[] = new SearchTerm("f");

        $qe = SearchTerm::combine("or", $qt);
        return $negated ? SearchTerm::negate($qe) : $qe;
    }

    static public function pop_word(&$str) {
        $wordre = '/\A\s*(?:"[^"]*"?|[a-zA-Z][a-zA-Z0-9]*:"[^"]*"?[^\s()]*|[^"\s()]+)/s';

        if (!preg_match($wordre, $str, $m))
            return ($str = "");
        $str = substr($str, strlen($m[0]));
        $word = ltrim($m[0]);

        // commas in paper number strings turn into separate words
        if (preg_match('/\A(#?\d+(?:-#?\d+)?),((?:#?\d+(?:-#?\d+)?,?)*)\z/', $word, $m)) {
            $word = $m[1];
            if ($m[2] !== "")
                $str = $m[2] . $str;
        }

        // check for keyword
        $keyword = false;
        if (($colon = strpos($word, ":")) > 0) {
            $x = substr($word, 0, $colon);
            if (strpos($x, '"') === false)
                $keyword = @self::$_keywords[$x] ? : $x;
        }

        // allow a space after a keyword
        if ($keyword && strlen($word) <= $colon + 1 && preg_match($wordre, $str, $m)) {
            $word .= $m[0];
            $str = substr($str, strlen($m[0]));
        }

        // "show:" may be followed by a parenthesized expression
        if ($keyword
            && (substr($str, 0, 1) === "(" || substr($str, 0, 2) === "-(")
            && substr($word, $colon + 1, 1) !== "\""
            && ($keyword === "show" || $keyword === "showsort"
                || $keyword === "sort" || $keyword === "formula")) {
            $pos = self::find_end_balanced_parens($str);
            $word .= substr($str, 0, $pos);
            $str = substr($str, $pos);
        }

        $str = ltrim($str);
        return $word;
    }

    static function _searchPopKeyword($str) {
        if (preg_match('/\A([-+()]|(?:AND|OR|NOT|THEN)(?=[\s\(]))/is', $str, $m))
            return array(strtoupper($m[1]), ltrim(substr($str, strlen($m[0]))));
        else
            return array(null, $str);
    }

    static function _searchPopStack($curqe, &$stack) {
        $x = array_pop($stack);
        if (!$curqe)
            return $x->leftqe;
        else if ($x->op->op === "not")
            return SearchTerm::negate($curqe);
        else if ($x->op->op === "+")
            return $curqe;
        else if ($x->used) {
            $x->leftqe->value[] = $curqe;
            return $x->leftqe;
        } else
            return SearchTerm::combine($x->op->op, array($x->leftqe, $curqe));
    }

    function _searchQueryType($str) {
        $stack = array();
        $defkwstack = array();
        $defkw = $next_defkw = null;
        $parens = 0;
        $curqe = null;
        $xstr = $str;
        $headstr = "";

        while ($str !== "") {
            list($opstr, $nextstr) = self::_searchPopKeyword($str);
            $op = $opstr ? SearchOperator::$list[$opstr] : null;

            if ($curqe && (!$op || $op->unary)) {
                list($opstr, $op, $nextstr) =
                    array("", SearchOperator::$list["SPACE"], $str);
            }

            if ($opstr === null) {
                $word = self::pop_word($nextstr);
                // Bare any-case "all", "any", "none" are treated as keywords.
                if (!$curqe
                    && (!count($stack) || $stack[count($stack) - 1]->op->op === "then")
                    && ($uword = strtoupper($word))
                    && ($uword === "ALL" || $uword === "ANY" || $uword === "NONE")
                    && preg_match(',\A\s*(?:|THEN(?:\s|\().*)\z,', $nextstr))
                    $word = $uword;
                // Search like "ti:(foo OR bar)" adds a default keyword.
                if ($word[strlen($word) - 1] === ":"
                    && $nextstr !== ""
                    && $nextstr[0] === "(")
                    $next_defkw = $word;
                else {
                    // If no keyword, but default keyword exists, apply it.
                    if ($defkw !== ""
                        && !preg_match(',\A-?[a-zA-Z][a-zA-Z0-9]*:,', $word)) {
                        if ($word[0] === "-")
                            $word = "-" . $defkw . substr($word, 1);
                        else
                            $word = $defkw . $word;
                    }
                    // The heart of the matter.
                    $curqe = $this->_searchQueryWord($word, true);
                    // Don't include 'show:' in headings.
                    if (($colon = strpos($word, ":")) !== false
                        && @self::$_noheading_keywords[substr($word, 0, $colon)]) {
                        $headstr .= substr($xstr, 0, -strlen($str));
                        $xstr = $nextstr;
                    }
                }
            } else if ($opstr === ")") {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->op !== "(")
                    $curqe = self::_searchPopStack($curqe, $stack);
                if (count($stack)) {
                    array_pop($stack);
                    --$parens;
                    $defkw = array_pop($defkwstack);
                }
            } else if ($opstr === "(") {
                assert(!$curqe);
                $stack[] = (object) array("op" => $op, "leftqe" => null, "used" => false);
                $defkwstack[] = $defkw;
                $defkw = $next_defkw;
                $next_defkw = null;
                ++$parens;
            } else if (!$op->unary && !$curqe)
                /* ignore bad operator */;
            else {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->precedence > $op->precedence)
                    $curqe = self::_searchPopStack($curqe, $stack);
                if ($op->op === "then" && $curqe) {
                    $curqe->set_float("substr", trim($headstr . substr($xstr, 0, -strlen($str))));
                    $xstr = $nextstr;
                    $headstr = "";
                }
                $top = count($stack) ? $stack[count($stack) - 1] : null;
                if ($top && !$op->unary && $top->op->op === $op->op) {
                    if ($top->used)
                        $top->leftqe->value[] = $curqe;
                    else {
                        $top->leftqe = SearchTerm::combine($op->op, array($top->leftqe, $curqe));
                        $top->used = true;
                    }
                } else
                    $stack[] = (object) array("op" => $op, "leftqe" => $curqe, "used" => false);
                $curqe = null;
            }

            $str = $nextstr;
        }

        if ($curqe)
            $curqe->set_float("substr", trim($headstr . $xstr));
        while (count($stack))
            $curqe = self::_searchPopStack($curqe, $stack);
        return $curqe;
    }

    static private function _canonicalizePopStack($curqe, &$stack) {
        $x = array_pop($stack);
        if ($curqe)
            $x->qe[] = $curqe;
        if (!count($x->qe))
            return null;
        if ($x->op->unary) {
            $qe = $x->qe[0];
            if ($x->op->op === "not") {
                if (preg_match('/\A(?:[(-]|NOT )/i', $qe))
                    $qe = "NOT $qe";
                else
                    $qe = "-$qe";
            }
            return $qe;
        } else if (count($x->qe) == 1)
            return $x->qe[0];
        else if ($x->op->op === "and2" && $x->op->precedence == 2)
            return "(" . join(" ", $x->qe) . ")";
        else
            return "(" . join(strtoupper(" " . $x->op->op . " "), $x->qe) . ")";
    }

    static private function _canonicalizeQueryType($str, $type) {
        $stack = array();
        $parens = 0;
        $defaultop = ($type === "all" ? "XAND" : "XOR");
        $curqe = null;
        $t = "";

        while ($str !== "") {
            list($opstr, $nextstr) = self::_searchPopKeyword($str);
            $op = $opstr ? SearchOperator::$list[$opstr] : null;

            if ($curqe && (!$op || $op->unary)) {
                list($opstr, $op, $nextstr) =
                    array("", SearchOperator::$list[$parens ? "XAND" : $defaultop], $str);
            }

            if ($opstr === null) {
                $curqe = self::pop_word($nextstr);
            } else if ($opstr === ")") {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->op !== "(")
                    $curqe = self::_canonicalizePopStack($curqe, $stack);
                if (count($stack)) {
                    array_pop($stack);
                    --$parens;
                }
            } else if ($opstr === "(") {
                assert(!$curqe);
                $stack[] = (object) array("op" => $op, "qe" => array());
                ++$parens;
            } else {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->precedence > $op->precedence)
                    $curqe = self::_canonicalizePopStack($curqe, $stack);
                $top = count($stack) ? $stack[count($stack) - 1] : null;
                if ($top && !$op->unary && $top->op->op === $op->op)
                    $top->qe[] = $curqe;
                else
                    $stack[] = (object) array("op" => $op, "qe" => array($curqe));
                $curqe = null;
            }

            $str = $nextstr;
        }

        if ($type === "none")
            array_unshift($stack, (object) array("op" => SearchOperator::$list["NOT"], "qe" => array()));
        while (count($stack))
            $curqe = self::_canonicalizePopStack($curqe, $stack);
        return $curqe;
    }

    static function canonical_query($qa, $qo = null, $qx = null) {
        $x = array();
        if ($qa && ($qa = self::_canonicalizeQueryType(trim($qa), "all")))
            $x[] = $qa;
        if ($qo && ($qo = self::_canonicalizeQueryType(trim($qo), "any")))
            $x[] = $qo;
        if ($qx && ($qx = self::_canonicalizeQueryType(trim($qx), "none")))
            $x[] = $qx;
        if (count($x) == 1)
            return preg_replace('/\A\((.*)\)\z/', '$1', join("", $x));
        else
            return join(" AND ", $x);
    }


    // CLEANING
    // Clean an input expression series into clauses.  The basic purpose of
    // this step is to combine all paper numbers into a single group, and to
    // assign review adjustments (rates & rounds).

    function _queryClean($qe, $below = false) {
        if (!$qe)
            return $qe;
        else if ($qe->type === "not")
            return $this->_queryCleanNot($qe);
        else if ($qe->type === "or")
            return $this->_queryCleanOr($qe);
        else if ($qe->type === "then")
            return $this->_queryCleanThen($qe, $below);
        else if ($qe->type === "and" || $qe->type === "and2")
            return $this->_queryCleanAnd($qe);
        else
            return $qe;
    }

    function _queryCleanNot($qe) {
        $qv = $this->_queryClean($qe->value, true);
        if ($qv->type === "not")
            return $qv->value;
        else if ($qv->type === "pn")
            return new SearchTerm("pn", 0, array($qv->value[1], $qv->value[0]));
        else if ($qv->type === "revadj") {
            $qv->value["revadjnegate"] = !defval($qv->value, "revadjnegate", false);
            return $qv;
        } else {
            $float = $qe->get("float");
            $qv = SearchTerm::extract_float($float, $qv);
            return SearchTerm::combine_float($float, "not", $qv);
        }
    }

    static function _reviewAdjustmentNegate($ra) {
        global $Conf;
        if (isset($ra->value["round"]))
            $ra->value["round"] = array_diff(array_keys($Conf->round_list()), $ra->value["round"]);
        if (isset($ra->value["rate"]))
            $ra->value["rate"] = "not (" . $ra->value["rate"] . ")";
        $ra->value["revadjnegate"] = false;
    }

    static function _reviewAdjustmentMerge($revadj, $qv, $op) {
        // XXX this is probably not right in fully general cases
        if (!$revadj)
            return $qv;
        list($neg1, $neg2) = array(defval($revadj->value, "revadjnegate"), defval($qv->value, "revadjnegate"));
        if ($neg1 !== $neg2 || ($neg1 && $op === "or")) {
            if ($neg1)
                self::_reviewAdjustmentNegate($revadj);
            if ($neg2)
                self::_reviewAdjustmentNegate($qv);
            $neg1 = $neg2 = false;
        }
        if ($op === "or" || $neg1) {
            if (isset($qv->value["round"]))
                $revadj->value["round"] = array_unique(array_merge(defval($revadj->value, "round", array()), $qv->value["round"]));
            if (isset($qv->value["rate"]))
                $revadj->value["rate"] = "(" . defval($revadj->value, "rate", "false") . ") or (" . $qv->value["rate"] . ")";
        } else {
            if (isset($revadj->value["round"]) && isset($qv->value["round"]))
                $revadj->value["round"] = array_intersect($revadj->value["round"], $qv->value["round"]);
            else if (isset($qv->value["round"]))
                $revadj->value["round"] = $qv->value["round"];
            if (isset($qv->value["rate"]))
                $revadj->value["rate"] = "(" . defval($revadj->value, "rate", "true") . ") and (" . $qv->value["rate"] . ")";
        }
        return $revadj;
    }

    function _queryCleanOr($qe) {
        $revadj = null;
        $float = $qe->get("float");
        $newvalues = array();

        foreach ($qe->value as $qv) {
            $qv = SearchTerm::extract_float($float, $this->_queryClean($qv, true));
            if ($qv && $qv->type === "revadj")
                $revadj = self::_reviewAdjustmentMerge($revadj, $qv, "or");
            else if ($qv)
                $newvalues[] = $qv;
        }

        if ($revadj && count($newvalues) == 0)
            return $revadj;
        else if ($revadj)
            $this->_reviewAdjustError = true;
        return SearchTerm::combine_float($float, "or", $newvalues);
    }

    function _queryCleanAnd($qe) {
        $pn = array(array(), array());
        $revadj = null;
        $float = $qe->get("float");
        $newvalues = array();

        foreach ($qe->value as $qv) {
            $qv = SearchTerm::extract_float($float, $this->_queryClean($qv, true));
            if ($qv && $qv->type === "pn" && $qe->type === "and2") {
                $pn[0] = array_merge($pn[0], $qv->value[0]);
                $pn[1] = array_merge($pn[1], $qv->value[1]);
            } else if ($qv && $qv->type === "revadj")
                $revadj = self::_reviewAdjustmentMerge($revadj, $qv, "and");
            else if ($qv)
                $newvalues[] = $qv;
        }

        if (count($pn[0]) || count($pn[1]))
            array_unshift($newvalues, new SearchTerm("pn", 0, $pn));
        if ($revadj)            // must be first
            array_unshift($newvalues, $revadj);
        return SearchTerm::combine_float($float, "and", $newvalues);
    }

    function _queryCleanThen($qe, $below) {
        if ($below) {
            $this->_thenError = true;
            $qe->type = "or";
            return $this->_queryCleanOr($qe);
        }
        $float = $qe->get("float");
        for ($i = 0; $i < count($qe->value); ) {
            $qv = $qe->value[$i];
            if ($qv->type === "then")
                array_splice($qe->value, $i, 1, $qv->value);
            else {
                $qe->value[$i] = SearchTerm::extract_float($float, $this->_queryClean($qv, true));
                ++$i;
            }
        }
        return SearchTerm::combine_float($float, "then", $qe->value);
    }

    // apply rounds to reviewer searches
    function _queryMakeAdjustedReviewSearch($roundterm) {
        if ($this->limitName === "r" || $this->limitName === "rout")
            $value = new SearchReviewValue(">0", array($this->cid));
        else if ($this->limitName === "req" || $this->limitName === "reqrevs")
            $value = new SearchReviewValue(">0", null, "requestedBy=" . $this->cid . " and reviewType=" . REVIEW_EXTERNAL);
        else
            $value = new SearchReviewValue(">0");
        $rt = $this->privChair ? 0 : self::F_NONCONFLICT;
        if (!$this->amPC)
            $rt |= self::F_REVIEWER;
        $term = new SearchTerm("re", $rt | self::F_XVIEW, $value, $roundterm->value);
        if (defval($roundterm->value, "revadjnegate")) {
            $term->set("revadjnegate", false);
            return SearchTerm::negate($term);
        } else
            return $term;
    }

    function _queryAdjustReviews($qe, $revadj) {
        $applied = $first_applied = 0;
        $adjustments = array("round", "rate");
        if ($qe->type === "not")
            $this->_queryAdjustReviews($qe->value, $revadj);
        else if ($qe->type === "and" || $qe->type === "and2") {
            $myrevadj = ($qe->value[0]->type === "revadj" ? $qe->value[0] : null);
            if ($myrevadj) {
                $used_revadj = false;
                foreach ($adjustments as $adj)
                    if (!isset($myrevadj->value[$adj]) && isset($revadj->value[$adj])) {
                        $myrevadj->value[$adj] = $revadj->value[$adj];
                        $used_revadj = true;
                    }
            }

            $rdown = $myrevadj ? $myrevadj : $revadj;
            for ($i = 0; $i < count($qe->value); ++$i)
                if ($qe->value[$i]->type !== "revadj")
                    $this->_queryAdjustReviews($qe->value[$i], $rdown);

            if ($myrevadj && !isset($myrevadj->used_revadj)) {
                $qe->value[0] = $this->_queryMakeAdjustedReviewSearch($myrevadj);
                if ($used_revadj)
                    $revadj->used_revadj = true;
            }
        } else if ($qe->type === "or" || $qe->type === "then") {
            for ($i = 0; $i < count($qe->value); ++$i)
                $this->_queryAdjustReviews($qe->value[$i], $revadj);
        } else if ($qe->type === "re" && $revadj) {
            foreach ($adjustments as $adj)
                if (isset($revadj->value[$adj]))
                    $qe->set($adj, $revadj->value[$adj]);
            $revadj->used_revadj = true;
        } else if ($qe->type === "revadj") {
            assert(!$revadj);
            return $this->_queryMakeAdjustedReviewSearch($qe);
        }
        return $qe;
    }

    function _queryExtractInfo($qe, $top, &$contradictions) {
        if ($qe->type === "and" || $qe->type === "and2"
            || $qe->type === "or" || $qe->type === "then") {
            $isand = $qe->type === "and" || $qe->type === "and2";
            foreach ($qe->value as $qv)
                $this->_queryExtractInfo($qv, $top && $isand, $contradictions);
        }
        if (($x = $qe->get("regex"))) {
            $this->regex[$x[0]] = defval($this->regex, $x[0], array());
            $this->regex[$x[0]][] = $x[1];
        }
        if (($x = $qe->get("tagorder")))
            $this->orderTags[] = $x;
        if ($top && $qe->type === "re" && !$this->_reviewer_fixed) {
            if ($this->_reviewer === false) {
                $v = $qe->value->contactsql;
                if ($v[0] === "=")
                    $this->_reviewer = (int) substr($v, 1);
            } else
                $this->_reviewer = null;
        }
        if ($top && ($x = $qe->get("contradiction_warning")))
            $contradictions[$x] = true;
    }


    // QUERY CONSTRUCTION
    // Build a database query corresponding to an expression.
    // The query may be liberal (returning more papers than actually match);
    // QUERY EVALUATION makes it precise.

    private function _clauseTermSetFlags($t, $sqi, &$q) {
        $flags = $t->flags;
        $this->needflags |= $flags;

        if ($flags & self::F_NONCONFLICT)
            $q[] = "PaperConflict.conflictType is null";
        if ($flags & self::F_AUTHOR)
            $q[] = $this->contact->actAuthorSql("PaperConflict");
        if ($flags & self::F_REVIEWER)
            $q[] = "MyReview.reviewNeedsSubmit=0";
        if ($flags & self::F_XVIEW) {
            $this->needflags |= self::F_NONCONFLICT | self::F_REVIEWER;
            $sqi->add_rights_columns();
        }
        if (($flags & self::F_FALSE)
            || ($sqi->negated && ($flags & self::F_XVIEW)))
            $q[] = "false";
    }

    private function _clauseTermSetField($t, $field, $sqi, &$f) {
        $this->needflags |= $t->flags;
        $v = $t->value;
        if ($v !== "" && $v[0] === "*")
            $v = substr($v, 1);
        if ($v !== "" && $v[strlen($v) - 1] === "*")
            $v = substr($v, 0, strlen($v) - 1);
        if ($sqi->negated)
            // The purpose of _clauseTermSetField is to match AT LEAST those
            // papers that contain "$t->value" as a word in the $field field.
            // A substring match contains at least those papers; but only if
            // the current term is positive (= not negated).  If the current
            // term is negated, we say NO paper matches this clause.  (NOT no
            // paper is every paper.)  Later code will check for a substring.
            $f[] = "false";
        else if (!ctype_alnum($v))
            $f[] = "true";
        else {
            $q = array();
            $this->_clauseTermSetFlags($t, $sqi, $q);
            $q[] = "convert(Paper.$field using utf8) like '%$v%'";
            $f[] = "(" . join(" and ", $q) . ")";
        }
        $t->link = $field;
        $this->needflags |= self::F_XVIEW;
    }

    private function _clauseTermSetTable($t, $value, $compar, $shorttab,
                                         $table, $field, $where, $sqi, &$f) {
        // see also first "tag" case below
        $q = array();
        $this->_clauseTermSetFlags($t, $sqi, $q);

        if ($value === "none" && !$compar)
            list($compar, $value) = array("=0", "");
        else if (($value === "" || $value === "any") && !$compar)
            list($compar, $value) = array(">0", "");
        else if (!$compar || $compar === ">=1")
            $compar = ">0";
        else if ($compar === "<=0" || $compar === "<1")
            $compar = "=0";

        $thistab = $shorttab . "_" . count($sqi->tables);
        if ($value === "") {
            if ($compar === ">0" || $compar === "=0")
                $thistab = "Any" . $shorttab;
            $tdef = array("left join", $table);
        } else if (is_array($value)) {
            if (count($value))
                $tdef = array("left join", $table, "$thistab.$field in (" . join(",", $value) . ")");
            else
                $tdef = array("left join", $table, "false");
        } else if ($value[0] === "\1") {
            $tdef = array("left join", $table, str_replace("\3", "$thistab.$field", "\3$value"));
        } else if ($value[0] === "\3") {
            $tdef = array("left join", $table, str_replace("\3", "$thistab.$field", $value));
        } else {
            $tdef = array("left join", $table, "$thistab.$field='" . sqlq($value) . "'");
        }
        if ($where)
            $tdef[2] .= str_replace("%", $thistab, $where);

        if ($compar !== ">0" && $compar !== "=0") {
            $tdef[1] = "(select paperId, count(*) ct from " . $tdef[1] . " as " . $thistab;
            if (count($tdef) > 2)
                $tdef[1] .= " where " . array_pop($tdef);
            $tdef[1] .= " group by paperId)";
            $sqi->add_column($thistab . "_ct", "$thistab.ct");
            $q[] = "coalesce($thistab.ct,0)$compar";
        } else {
            $sqi->add_column($thistab . "_ct", "count($thistab.$field)");
            if ($compar === "=0")
                $q[] = "$thistab.$field is null";
            else
                $q[] = "$thistab.$field is not null";
        }

        $sqi->add_table($thistab, $tdef);
        $t->link = $thistab . "_ct";
        $f[] = "(" . join(" and ", $q) . ")";
    }

    static function unusableRatings($privChair, $contactId) {
        global $Conf;
        if ($privChair || $Conf->timePCViewAllReviews())
            return array();
        $noratings = array();
        $rateset = $Conf->setting("rev_rating");
        if ($rateset == REV_RATINGS_PC)
            $npr_constraint = "reviewType>" . REVIEW_EXTERNAL;
        else
            $npr_constraint = "true";
        // This query supposedly returns those reviewIds whose ratings
        // are not visible to the current querier
        $result = Dbl::qe("select MPR.reviewId
        from PaperReview as MPR
        left join (select paperId, count(reviewId) as numReviews from PaperReview where $npr_constraint and reviewNeedsSubmit<=0 group by paperId) as NPR on (NPR.paperId=MPR.paperId)
        left join (select paperId, count(rating) as numRatings from PaperReview join ReviewRating using (reviewId) group by paperId) as NRR on (NRR.paperId=MPR.paperId)
        where MPR.contactId=$contactId
        and numReviews<=2
        and numRatings<=2");
        return Dbl::fetch_first_columns($result);
    }

    private function _clauseTermSetRating(&$reviewtable, &$where, $rate) {
        $noratings = "";
        if ($this->noratings === false)
            $this->noratings = self::unusableRatings($this->privChair, $this->cid);
        if (count($this->noratings) > 0)
            $noratings .= " and not (reviewId in (" . join(",", $this->noratings) . "))";
        else
            $noratings = "";

        foreach ($this->interestingRatings as $k => $v)
            $reviewtable .= " left join (select reviewId, count(rating) as nrate_$k from ReviewRating where rating$v$noratings group by reviewId) as Ratings_$k on (Ratings_$k.reviewId=r.reviewId)";
        $where[] = $rate;
    }

    private function _clauseTermSetReviews($thistab, $extrawhere, $t, $sqi) {
        if (!isset($sqi->tables[$thistab])) {
            $where = array();
            $reviewtable = "PaperReview r";
            if ($t->flags & self::F_REVIEWTYPEMASK)
                $where[] = "reviewType=" . ($t->flags & self::F_REVIEWTYPEMASK);
            if ($t->flags & self::F_COMPLETE)
                $where[] = "reviewSubmitted>0";
            else if ($t->flags & self::F_INCOMPLETE)
                $where[] = "reviewNeedsSubmit>0";
            else if ($t->flags & self::F_INPROGRESS) {
                $where[] = "reviewNeedsSubmit>0";
                $where[] = "reviewModified>0";
            }
            $rrnegate = $t->get("revadjnegate");
            if (($x = $t->get("round")) !== null) {
                if (count($x) == 0)
                    $where[] = $rrnegate ? "true" : "false";
                else
                    $where[] = "reviewRound " . ($rrnegate ? "not " : "") . "in (" . join(",", $x) . ")";
            }
            if (($x = $t->get("rate")) !== null)
                $this->_clauseTermSetRating($reviewtable, $where, $rrnegate ? "(not $x)" : $x);
            if ($extrawhere)
                $where[] = $extrawhere;
            $wheretext = "";
            if (count($where))
                $wheretext = " where " . join(" and ", $where);
            $sqi->add_table($thistab, array("left join", "(select r.paperId, count(r.reviewId) count, group_concat(r.reviewId, ' ', r.contactId, ' ', r.reviewType, ' ', coalesce(r.reviewSubmitted,0), ' ', r.reviewNeedsSubmit, ' ', r.requestedBy, ' ', r.reviewToken, ' ', r.reviewBlind) info from $reviewtable$wheretext group by paperId)"));
            $sqi->add_column($thistab . "_info", $thistab . ".info");
        }
        $q = array();
        $this->_clauseTermSetFlags($t, $sqi, $q);
        // Make the database query conservative (so change equality
        // constraints to >= constraints, and ignore <=/</!= constraints).
        // We'll do the precise query later.
        $q[] = "coalesce($thistab.count,0)" . $t->value->conservative_countexpr();
        $t->link = $thistab;
        return "(" . join(" and ", $q) . ")";
    }

    private function _clauseTermSetRevpref($thistab, $extrawhere, $t, $sqi) {
        if (!isset($sqi->tables[$thistab])) {
            $where = array();
            $reviewtable = "PaperReviewPreference";
            if ($extrawhere)
                $where[] = $extrawhere;
            $wheretext = "";
            if (count($where))
                $wheretext = " where " . join(" and ", $where);
            $sqi->add_table($thistab, array("left join", "(select paperId, count(PaperReviewPreference.preference) as count from $reviewtable$wheretext group by paperId)"));
        }
        $q = array();
        $this->_clauseTermSetFlags($t, $sqi, $q);
        $q[] = "coalesce($thistab.count,0)" . $t->value->countexpr;
        $sqi->add_column($thistab . "_matches", "$thistab.count");
        $t->link = $thistab . "_matches";
        return "(" . join(" and ", $q) . ")";
    }

    private function _clauseTermSetComments($thistab, $extrawhere, $t, $sqi) {
        global $Conf;
        if (!isset($sqi->tables[$thistab])) {
            $where = array();
            if (!($t->flags & self::F_ALLOWRESPONSE))
                $where[] = "(commentType&" . COMMENTTYPE_RESPONSE . ")=0";
            if (!($t->flags & self::F_ALLOWCOMMENT))
                $where[] = "(commentType&" . COMMENTTYPE_RESPONSE . ")!=0";
            if (!($t->flags & self::F_ALLOWDRAFT))
                $where[] = "(commentType&" . COMMENTTYPE_DRAFT . ")=0";
            else if ($t->flags & self::F_REQUIREDRAFT)
                $where[] = "(commentType&" . COMMENTTYPE_DRAFT . ")!=0";
            if ($t->flags & self::F_AUTHORCOMMENT)
                $where[] = "commentType>=" . COMMENTTYPE_AUTHOR;
            if (@$t->commentRound !== null)
                $where[] = "commentRound=" . $t->commentRound;
            if ($extrawhere)
                $where[] = $extrawhere;
            $wheretext = "";
            if (count($where))
                $wheretext = " where " . join(" and ", $where);
            $sqi->add_table($thistab, array("left join", "(select paperId, count(commentId) count, group_concat(contactId, ' ', commentType) info from PaperComment$wheretext group by paperId)"));
            $sqi->add_column($thistab . "_info", $thistab . ".info");
        }
        $q = array();
        $this->_clauseTermSetFlags($t, $sqi, $q);
        $q[] = "coalesce($thistab.count,0)" . $t->value->conservative_countexpr();
        $t->link = $thistab;
        return "(" . join(" and ", $q) . ")";
    }

    private function _clauseTermSet(&$t, $sqi, &$f) {
        $tt = $t->type;
        $thistab = null;

        // collect columns
        if ($tt === "ti") {
            $sqi->add_column("title", "Paper.title");
            $this->_clauseTermSetField($t, "title", $sqi, $f);
        } else if ($tt === "ab") {
            $sqi->add_column("abstract", "Paper.abstract");
            $this->_clauseTermSetField($t, "abstract", $sqi, $f);
        } else if ($tt === "au") {
            $sqi->add_column("authorInformation", "Paper.authorInformation");
            $this->_clauseTermSetField($t, "authorInformation", $sqi, $f);
        } else if ($tt === "co") {
            $sqi->add_column("collaborators", "Paper.collaborators");
            $this->_clauseTermSetField($t, "collaborators", $sqi, $f);
        } else if ($tt === "au_cid") {
            $this->_clauseTermSetTable($t, $t->value, null, "AuthorConflict",
                                       "PaperConflict", "contactId",
                                       " and " . $this->contact->actAuthorSql("%"),
                                       $sqi, $f);
        } else if ($tt === "re") {
            $extrawhere = array();
            if ($t->value->contactsql)
                $extrawhere[] = $t->value->contactWhere("r.contactId");
            if ($t->value->fieldsql)
                $extrawhere[] = $t->value->fieldsql;
            $extrawhere = join(" and ", $extrawhere);
            if ($extrawhere === "" && $t->get("round") === null && $t->get("rate") === null)
                $thistab = "Numreviews_" . ($t->flags & (self::F_REVIEWTYPEMASK | self::F_COMPLETE | self::F_INCOMPLETE));
            else
                $thistab = "Reviews_" . count($sqi->tables);
            $f[] = $this->_clauseTermSetReviews($thistab, $extrawhere, $t, $sqi);
        } else if ($tt === "revpref") {
            $extrawhere = array();
            if ($t->value->contactsql)
                $extrawhere[] = $t->value->contactWhere("contactId");
            if ($t->value->fieldsql)
                $extrawhere[] = $t->value->fieldsql;
            $extrawhere = join(" and ", $extrawhere);
            $thistab = "Revpref_" . count($sqi->tables);
            $f[] = $this->_clauseTermSetRevpref($thistab, $extrawhere, $t, $sqi);
        } else if ($tt === "conflict") {
            $this->_clauseTermSetTable($t, "\3" . $t->value->contactsql, $t->value->countexpr, "Conflict",
                                       "PaperConflict", "contactId", "",
                                       $sqi, $f);
        } else if ($tt === "cmt") {
            if ($t->value->contactsql)
                $thistab = "Comments_" . count($sqi->tables);
            else {
                $rtype = $t->flags & (self::F_ALLOWCOMMENT | self::F_ALLOWRESPONSE | self::F_AUTHORCOMMENT | self::F_ALLOWDRAFT | self::F_REQUIREDRAFT);
                $thistab = "Numcomments_" . $rtype;
                if (@$t->commentRound !== null)
                    $thistab .= "_" . $t->commentRound;
            }
            $f[] = $this->_clauseTermSetComments($thistab, $t->value->contactWhere("contactId"), $t, $sqi);
        } else if ($tt === "cmttag") {
            $thistab = "TaggedComments_" . count($sqi->tables);
            if ($t->value->contactsql === "any")
                $match = "is not null";
            else
                $match = "like '% " . sqlq($t->value->contactsql) . " %'";
            $f[] = $this->_clauseTermSetComments($thistab, "commentTags $match", $t, $sqi);
        } else if ($tt === "pn") {
            $q = array();
            if (count($t->value[0]))
                $q[] = "Paper.paperId in (" . join(",", $t->value[0]) . ")";
            if (count($t->value[1]))
                $q[] = "Paper.paperId not in (" . join(",", $t->value[1]) . ")";
            if (!count($q))
                $q[] = "false";
            $f[] = "(" . join(" and ", $q) . ")";
        } else if ($tt === "pf") {
            $q = array();
            $this->_clauseTermSetFlags($t, $sqi, $q);
            for ($i = 0; $i < count($t->value); $i += 2) {
                if (is_array($t->value[$i + 1]))
                    $q[] = "Paper." . $t->value[$i] . " in (" . join(",", $t->value[$i + 1]) . ")";
                else
                    $q[] = "Paper." . $t->value[$i] . $t->value[$i + 1];
            }
            $f[] = "(" . join(" and ", $q) . ")";
            for ($i = 0; $i < count($t->value); $i += 2)
                $sqi->add_column($t->value[$i], "Paper." . $t->value[$i]);
        } else if ($tt === "tag") {
            $extra = "";
            for ($i = 1; $i < count($t->value); ++$i)
                $extra .= " and %.tagIndex" . $t->value[$i];
            $this->_clauseTermSetTable($t, $t->value[0], null, "Tag",
                                       "PaperTag", "tag", $extra,
                                       $sqi, $f);
        } else if ($tt === "topic") {
            $this->_clauseTermSetTable($t, $t->value, null, "Topic",
                                       "PaperTopic", "topicId", "",
                                       $sqi, $f);
        } else if ($tt === "option") {
            // expanded from _clauseTermSetTable
            $q = array();
            $this->_clauseTermSetFlags($t, $sqi, $q);
            $thistab = "Option_" . count($sqi->tables);
            $sqi->add_table($thistab, array("left join", "PaperOption", "$thistab.optionId=" . $t->value[0]->id));
            $sqi->add_column($thistab . "_x", "coalesce($thistab.value,0)" . $t->value[1]);
            $t->link = $thistab . "_x";
            $q[] = $sqi->columns[$t->link];
            $f[] = "(" . join(" and ", $q) . ")";
        } else if ($tt === "formula") {
            $q = array("true");
            $this->_clauseTermSetFlags($t, $sqi, $q);
            $t->value->add_query_options($this->_query_options, $this->contact);
            if (!$t->link)
                $t->link = $t->value->compile_function($this->contact);
            $f[] = "(" . join(" and ", $q) . ")";
        } else if ($tt === "not") {
            $ff = array();
            $sqi->negated = !$sqi->negated;
            $this->_clauseTermSet($t->value, $sqi, $ff);
            $sqi->negated = !$sqi->negated;
            if (!count($ff))
                $ff[] = "true";
            $f[] = "not (" . join(" or ", $ff) . ")";
        } else if ($tt === "and" || $tt === "and2") {
            $ff = array();
            foreach ($t->value as $subt)
                $this->_clauseTermSet($subt, $sqi, $ff);
            if (!count($ff))
                $ff[] = "false";
            $f[] = "(" . join(" and ", $ff) . ")";
        } else if ($tt === "or" || $tt === "then") {
            $ff = array();
            foreach ($t->value as $subt)
                $this->_clauseTermSet($subt, $sqi, $ff);
            if (!count($ff))
                $ff[] = "false";
            $f[] = "(" . join(" or ", $ff) . ")";
        } else if ($tt === "f")
            $f[] = "false";
        else if ($tt === "t")
            $f[] = "true";
    }


    // QUERY EVALUATION
    // Check the results of the query, reducing the possibly conservative
    // overestimate produced by the database to a precise result.

    private function _clauseTermCheckFlags($t, $row) {
        $flags = $t->flags;
        if (($flags & self::F_AUTHOR)
            && !$this->contact->actAuthorView($row))
            return false;
        if (($flags & self::F_REVIEWER)
            && $row->myReviewNeedsSubmit !== 0
            && $row->myReviewNeedsSubmit !== "0")
            return false;
        if (($flags & self::F_NONCONFLICT) && $row->conflictType)
            return false;
        if ($flags & self::F_XVIEW) {
            if (!$this->contact->can_view_paper($row))
                return false;
            if ($t->type === "tag" && !$this->contact->can_view_tags($row, true))
                return false;
            if (($t->type === "au" || $t->type === "au_cid" || $t->type === "co")
                && !$this->contact->can_view_authors($row, true))
                return false;
            if ($t->type === "conflict"
                && !$this->contact->can_view_conflicts($row, true))
                return false;
            if ($t->type === "pf" && $t->value[0] === "outcome"
                && !$this->contact->can_view_decision($row, true))
                return false;
            if ($t->type === "option"
                && !$this->contact->can_view_paper_option($row, $t->value[0], true))
                return false;
            if ($t->type === "re" && ($fieldname = $t->link)
                && !isset($row->$fieldname)) {
                $row->$fieldname = 0;
                $rrow = (object) array("paperId" => $row->paperId);
                $count_only = !$t->value->fieldsql;
                foreach (explode(",", defval($row, $fieldname . "_info", "")) as $info)
                    if ($info !== "") {
                        list($rrow->reviewId, $rrow->contactId, $rrow->reviewType, $rrow->reviewSubmitted, $rrow->reviewNeedsSubmit, $rrow->requestedBy, $rrow->reviewToken, $rrow->reviewBlind) = explode(" ", $info);
                        if (($count_only
                             ? $this->contact->can_count_review($row, $rrow, true)
                             : $this->contact->can_view_review($row, $rrow, true))
                            && (!$t->value->contactsql
                                || $this->contact->can_view_review_identity($row, $rrow, true))
                            && (!isset($t->value->view_score)
                                || $t->value->view_score > $this->contact->view_score_bound($row, $rrow)))
                            ++$row->$fieldname;
                    }
            }
            if (($t->type === "cmt" || $t->type === "cmttag")
                && ($fieldname = $t->link)
                && !isset($row->$fieldname)) {
                $row->$fieldname = 0;
                $crow = (object) array("paperId" => $row->paperId);
                foreach (explode(",", defval($row, $fieldname . "_info", "")) as $info)
                    if ($info !== "") {
                        list($crow->contactId, $crow->commentType) = explode(" ", $info);
                        if ($this->contact->can_view_comment($row, $crow, true))
                            ++$row->$fieldname;
                    }
            }
            if ($t->type === "pf" && $t->value[0] === "leadContactId"
                && !$this->contact->can_view_lead($row, true))
                return false;
            if ($t->type === "pf" && $t->value[0] === "shepherdContactId"
                && !$this->contact->can_view_shepherd($row, true))
                return false;
            if ($t->type === "pf" && $t->value[0] === "managerContactId"
                && !$this->contact->can_view_paper_manager($row))
                return false;
        }
        if ($flags & self::F_FALSE)
            return false;
        return true;
    }

    function _clauseTermCheckField($t, $row) {
        $field = $t->link;
        if (!$this->_clauseTermCheckFlags($t, $row)
            || $row->$field === "")
            return false;

        $field_deaccent = $field . "_deaccent";
        if (!isset($row->$field_deaccent)) {
            if (preg_match('/[\x80-\xFF]/', $row->$field))
                $row->$field_deaccent = UnicodeHelper::deaccent($row->$field);
            else
                $row->$field_deaccent = false;
        }

        if (!isset($t->preg_utf8))
            self::analyze_field_preg($t);
        return self::match_field_preg($t, $row->$field, $row->$field_deaccent);
    }

    function _clauseTermCheck($t, $row) {
        $tt = $t->type;

        // collect columns
        if ($tt === "ti" || $tt === "ab" || $tt === "au" || $tt === "co")
            return $this->_clauseTermCheckField($t, $row);
        else if ($tt === "au_cid") {
            assert(is_array($t->value));
            return $this->_clauseTermCheckFlags($t, $row)
                && $row->{$t->link} != 0;
        } else if ($tt === "re" || $tt === "conflict" || $tt === "revpref"
                   || $tt === "cmt" || $tt === "cmttag") {
            if (!$this->_clauseTermCheckFlags($t, $row))
                return false;
            else {
                $fieldname = $t->link;
                return $t->value->test((int) $row->$fieldname);
            }
        } else if ($tt === "pn") {
            if (count($t->value[0]) && array_search($row->paperId, $t->value[0]) === false)
                return false;
            else if (count($t->value[1]) && array_search($row->paperId, $t->value[1]) !== false)
                return false;
            else
                return true;
        } else if ($tt === "pf") {
            if (!$this->_clauseTermCheckFlags($t, $row))
                return false;
            else {
                $ans = true;
                for ($i = 0; $ans && $i < count($t->value); $i += 2) {
                    $fieldname = $t->value[$i];
                    $expr = $t->value[$i + 1];
                    if (is_array($expr))
                        $ans = in_array($row->$fieldname, $expr);
                    else if ($expr[0] === '=')
                        $ans = $row->$fieldname == substr($expr, 1);
                    else if ($expr[0] === '!')
                        $ans = $row->$fieldname != substr($expr, 2);
                    else if ($expr[0] === '<' && $expr[1] === '=')
                        $ans = $row->$fieldname <= substr($expr, 2);
                    else if ($expr[0] === '>' && $expr[1] === '=')
                        $ans = $row->$fieldname >= substr($expr, 2);
                    else if ($expr[0] === '<')
                        $ans = $row->$fieldname < substr($expr, 1);
                    else if ($expr[0] === '>')
                        $ans = $row->$fieldname > substr($expr, 1);
                    else
                        $ans = false;
                }
                return $ans;
            }
        } else if ($tt === "tag" || $tt === "topic" || $tt === "option") {
            if (!$this->_clauseTermCheckFlags($t, $row))
                return false;
            else {
                $fieldname = $t->link;
                if ($t->value[0] === "none")
                    return $row->$fieldname == 0;
                else
                    return $row->$fieldname != 0;
            }
        } else if ($tt === "formula") {
            $formulaf = $t->link;
            return !!$formulaf($row, null, $this->contact);
        } else if ($tt === "not") {
            return !$this->_clauseTermCheck($t->value, $row);
        } else if ($tt === "and" || $tt === "and2") {
            foreach ($t->value as $subt)
                if (!$this->_clauseTermCheck($subt, $row))
                    return false;
            return true;
        } else if ($tt === "or" || $tt === "then") {
            foreach ($t->value as $subt)
                if ($this->_clauseTermCheck($subt, $row))
                    return true;
            return false;
        } else if ($tt === "f")
            return false;
        else if ($tt === "t" || $tt === "float" || $tt === "revadj")
            return true;
        else {
            error_log("PaperSearch::_clauseTermCheck: $tt defaults, correctness unlikely");
            return true;
        }
    }

    private function _add_deleted_papers($qe) {
        if ($qe->type === "or" || $qe->type === "then") {
            foreach ($qe->value as $subt)
                $this->_add_deleted_papers($subt);
        } else if ($qe->type === "pn") {
            foreach ($qe->value[0] as $p)
                if (array_search($p, $this->_matches) === false)
                    $this->_matches[] = $p;
        }
    }


    // BASIC QUERY FUNCTION

    function _search() {
        global $Conf;
        if ($this->_matches === false)
            return false;
        assert($this->_matches === null);

        if ($this->limitName === "x") {
            $this->_matches = array();
            return true;
        }

        // parse and clean the query
        $qe = $this->_searchQueryType($this->q);
        //$Conf->infoMsg(Ht::pre_text(var_export($qe, true)));
        if (!$qe)
            $qe = new SearchTerm("t");

        // apply complex limiters (only current example: "acc" for non-chairs)
        $limit = $this->limitName;
        if ($limit === "acc" && !$this->privChair)
            $qe = SearchTerm::combine("and", array($qe, $this->_searchQueryWord("dec:yes", false)));

        // clean query
        $qe = $this->_queryClean($qe);
        // apply review rounds (top down, needs separate step)
        if ($this->reviewAdjust) {
            $qe = $this->_queryAdjustReviews($qe, null);
            if ($this->_reviewAdjustError)
                $this->warn("Unexpected use of “round:” or “rate:” ignored.  Stick to the basics, such as “re:reviewername round:roundname”.");
        }

        //$Conf->infoMsg(Ht::pre_text(var_export($qe, true)));

        // collect clauses into tables, columns, and filters
        $sqi = new SearchQueryInfo;
        $sqi->add_table("Paper");
        $sqi->add_column("paperId", "Paper.paperId");
        // always include columns needed by rights machinery
        $sqi->add_column("timeSubmitted", "Paper.timeSubmitted");
        $sqi->add_column("timeWithdrawn", "Paper.timeWithdrawn");
        $sqi->add_column("outcome", "Paper.outcome");
        $filters = array();
        $this->_clauseTermSet($qe, $sqi, $filters);
        //$Conf->infoMsg(Ht::pre_text(var_export($filters, true)));

        // status limitation parts
        if ($limit === "rable") {
            $limitcontact = $this->_reviewer_fixed ? $this->reviewer() : $this->contact;
            if ($limitcontact->can_accept_review_assignment_ignore_conflict(null))
                $limit = $Conf->can_pc_see_all_submissions() ? "act" : "s";
            else if (!$limitcontact->isPC)
                $limit = "r";
        }
        if ($limit === "s" || $limit === "req"
            || $limit === "acc" || $limit === "und"
            || $limit === "unm"
            || ($limit === "rable" && !$Conf->can_pc_see_all_submissions()))
            $filters[] = "Paper.timeSubmitted>0";
        else if ($limit === "act" || $limit === "r" || $limit === "rable")
            $filters[] = "Paper.timeWithdrawn<=0";
        else if ($limit === "unsub")
            $filters[] = "(Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0)";
        else if ($limit === "lead")
            $filters[] = "Paper.leadContactId=" . $this->cid;
        else if ($limit === "manager") {
            if ($this->privChair)
                $filters[] = "(Paper.managerContactId=" . $this->cid . " or Paper.managerContactId=0)";
            else
                $filters[] = "Paper.managerContactId=" . $this->cid;
            $filters[] = "Paper.timeSubmitted>0";
        }

        // decision limitation parts
        if ($limit === "acc")
            $filters[] = "Paper.outcome>0";
        else if ($limit === "und")
            $filters[] = "Paper.outcome=0";

        // other search limiters
        if ($limit === "a") {
            $filters[] = $this->contact->actAuthorSql("PaperConflict");
            $this->needflags |= self::F_AUTHOR;
        } else if ($limit === "r") {
            $filters[] = "MyReview.reviewType is not null";
            $this->needflags |= self::F_REVIEWER;
        } else if ($limit === "ar") {
            $filters[] = "(" . $this->contact->actAuthorSql("PaperConflict") . " or (Paper.timeWithdrawn<=0 and MyReview.reviewType is not null))";
            $this->needflags |= self::F_AUTHOR | self::F_REVIEWER;
        } else if ($limit === "rout") {
            $filters[] = "MyReview.reviewNeedsSubmit!=0";
            $this->needflags |= self::F_REVIEWER;
        } else if ($limit === "revs")
            $sqi->add_table("Limiter", array("join", "PaperReview"));
        else if ($limit === "req")
            $sqi->add_table("Limiter", array("join", "PaperReview", "Limiter.requestedBy=$this->cid and Limiter.reviewType=" . REVIEW_EXTERNAL));
        else if ($limit === "unm")
            $filters[] = "Paper.managerContactId=0";

        // add common tables: conflicts, my own review, paper blindness
        if ($this->needflags & (self::F_NONCONFLICT | self::F_AUTHOR)) {
            $sqi->add_table("PaperConflict", array("left join", "PaperConflict", "PaperConflict.contactId=$this->cid"));
            $sqi->add_column("conflictType", "PaperConflict.conflictType");
        }
        if ($this->needflags & self::F_REVIEWER) {
            if ($Conf->submission_blindness() == Conference::BLIND_OPTIONAL)
                $sqi->add_column("paperBlind", "Paper.blind");
            $qb = "";
            if (($tokens = $this->contact->review_tokens()))
                $qb = " or MyReview.reviewToken in (" . join(",", $tokens) . ")";
            $sqi->add_table("MyReview", array("left join", "PaperReview", "(MyReview.contactId=$this->cid$qb)"));
            $sqi->add_column("myReviewType", "MyReview.reviewType");
            $sqi->add_column("myReviewNeedsSubmit", "MyReview.reviewNeedsSubmit");
            $sqi->add_column("myReviewSubmitted", "MyReview.reviewSubmitted");
        }

        // add permissions tables if we will filter the results
        $need_filter = (($this->needflags & self::F_XVIEW)
                        || $Conf->has_tracks()
                        || $qe->type === "then"
                        || $qe->get_float("heading")
                        || $limit === "rable");
        if ($need_filter) {
            $sqi->add_rights_columns();
            if ($Conf->setting("sub_blind") == Conference::BLIND_OPTIONAL)
                $sqi->add_column("paperBlind", "Paper.blind");
        }

        // XXX some of this should be shared with paperQuery
        if (($need_filter && $Conf->has_track_tags())
            || @$this->_query_options["tags"]) {
            $sqi->add_table("PaperTags", array("left join", "(select paperId, group_concat(' ', tag, '#', tagIndex separator '') as paperTags from PaperTag group by paperId)"));
            $sqi->add_column("paperTags", "PaperTags.paperTags");
        }
        if (@$this->_query_options["scores"] || @$this->_query_options["reviewTypes"] || @$this->_query_options["reviewContactIds"]) {
            $j = "group_concat(contactId order by reviewId) reviewContactIds";
            $sqi->add_column("reviewContactIds", "R_submitted.reviewContactIds");
            if (@$this->_query_options["reviewTypes"]) {
                $j .= ", group_concat(reviewType order by reviewId) reviewTypes";
                $sqi->add_column("reviewTypes", "R_submitted.reviewTypes");
            }
            foreach (@$this->_query_options["scores"] ? : array() as $f) {
                $j .= ", group_concat($f order by reviewId) {$f}Scores";
                $sqi->add_column("{$f}Scores", "R_submitted.{$f}Scores");
            }
            $sqi->add_table("R_submitted", array("left join", "(select paperId, $j from PaperReview where reviewSubmitted>0 group by paperId)"));
        }

        // create query
        $q = "select ";
        foreach ($sqi->columns as $colname => $value)
            $q .= $value . " " . $colname . ", ";
        $q = substr($q, 0, strlen($q) - 2) . "\n    from ";
        foreach ($sqi->tables as $tabname => $value)
            if (!$value)
                $q .= $tabname;
            else {
                $joiners = array("$tabname.paperId=Paper.paperId");
                for ($i = 2; $i < count($value); ++$i)
                    $joiners[] = "(" . $value[$i] . ")";
                $q .= "\n    " . $value[0] . " " . $value[1] . " as " . $tabname
                    . " on (" . join("\n        and ", $joiners) . ")";
            }
        if (count($filters))
            $q .= "\n    where " . join("\n        and ", $filters);
        $q .= "\n    group by Paper.paperId";

        //$Conf->infoMsg(Ht::pre_text_wrap($q));

        // actually perform query
        $result = Dbl::qe_raw($q);
        if (!$result)
            return ($this->_matches = false);
        $this->_matches = array();

        // correct query, create thenmap and headingmap
        $this->thenmap = ($qe->type === "then" ? array() : null);
        $this->headingmap = array();
        if ($need_filter) {
            $delete = array();
            $qe_heading = $qe->get_float("heading");
            while (($row = PaperInfo::fetch($result, $this->cid))) {
                if (!$this->contact->can_view_paper($row)
                    || ($limit === "rable"
                        && !$limitcontact->can_accept_review_assignment_ignore_conflict($row)))
                    $x = false;
                else if ($this->thenmap !== null) {
                    $x = false;
                    for ($i = 0; $i < count($qe->value) && $x === false; ++$i)
                        if ($this->_clauseTermCheck($qe->value[$i], $row))
                            $x = $i;
                } else
                    $x = !!$this->_clauseTermCheck($qe, $row);
                if ($x === false)
                    continue;
                $this->_matches[] = (int) $row->paperId;
                if ($this->thenmap !== null) {
                    $this->thenmap[$row->paperId] = $x;
                    $qex = $qe->value[$x];
                    $this->headingmap[$row->paperId] =
                        $qex->get_float("heading", $qex->get_float("substr", ""));
                } else if ($qe_heading)
                    $this->headingmap[$row->paperId] = $qe_heading;
            }
            if (!count($this->headingmap))
                $this->headingmap = null;
        } else
            while (($row = $result->fetch_object()))
                $this->_matches[] = (int) $row->paperId;
        $this->viewmap = $qe->get_float("view", array());
        $this->sorters = $qe->get_float("sort", array());
        Dbl::free($result);

        // add deleted papers explicitly listed by number (e.g. action log)
        if ($this->_allow_deleted)
            $this->_add_deleted_papers($qe);

        // extract regular expressions and set _reviewer if the query is
        // about exactly one reviewer, and warn about contradictions
        $contradictions = array();
        $this->_queryExtractInfo($qe, true, $contradictions);
        foreach ($contradictions as $contradiction => $garbage)
            $this->warn($contradiction);

        // set $this->matchPreg from $this->regex
        if (!$this->overrideMatchPreg) {
            $this->matchPreg = array();
            foreach (array("ti" => "title", "au" => "authorInformation",
                           "ab" => "abstract", "co" => "collaborators")
                     as $k => $v)
                if (isset($this->regex[$k]) && count($this->regex[$k])) {
                    $a = $b = array();
                    foreach ($this->regex[$k] as $x) {
                        $a[] = $x->preg_utf8;
                        if (isset($x->preg_raw))
                            $b[] = $x->preg_raw;
                    }
                    $x = (object) array("preg_utf8" => join("|", $a));
                    if (count($a) == count($b))
                        $x->preg_raw = join("|", $b);
                    $this->matchPreg[$v] = $x;
                }
        }

        return true;
    }

    function complexSearch(&$queryOptions) {
        global $Conf;
        $limit = $this->limitName;
        if (($limit === "s" || $limit === "act")
            && $this->q === "re:me")
            $limit = "r";
        else if ($this->q !== "")
            return true;
        if ($Conf->has_tracks()) {
            if (!$this->privChair || $limit === "rable")
                return true;
        }
        if ($limit === "rable") {
            $c = ($this->_reviewer_fixed ? $this->reviewer() : $this->contact);
            if ($c->isPC)
                $limit = $Conf->can_pc_see_all_submissions() ? "act" : "s";
            else
                $limit = "r";
        }
        if ($limit === "s" || $limit === "revs")
            $queryOptions["finalized"] = 1;
        else if ($limit === "unsub") {
            $queryOptions["unsub"] = 1;
            $queryOptions["active"] = 1;
        } else if ($limit === "acc") {
            if ($this->privChair || $Conf->timeAuthorViewDecision()) {
                $queryOptions["accepted"] = 1;
                $queryOptions["finalized"] = 1;
            } else
                return true;
        } else if ($limit === "und") {
            $queryOptions["undecided"] = 1;
            $queryOptions["finalized"] = 1;
        } else if ($limit === "r")
            $queryOptions["myReviews"] = 1;
        else if ($limit === "rout")
            $queryOptions["myOutstandingReviews"] = 1;
        else if ($limit === "a") {
            // If complex author SQL, always do search the long way
            if ($this->contact->actAuthorSql("%", true))
                return true;
            $queryOptions["author"] = 1;
        } else if ($limit === "req" || $limit === "reqrevs")
            $queryOptions["myReviewRequests"] = 1;
        else if ($limit === "act")
            $queryOptions["active"] = 1;
        else if ($limit === "lead")
            $queryOptions["myLead"] = 1;
        else if ($limit === "unm")
            $queryOptions["finalized"] = $queryOptions["unmanaged"] = 1;
        else if ($limit === "all")
            /* no limit */;
        else
            return true; /* don't understand limit */
        return false;
    }

    function numbered_papers() {
        $q = $this->q;
        $ss_recursion = array();
        while (1) {
            if (preg_match('/\A\s*#?\d[-#\d\s]*\z/s', $q)) {
                $a = array();
                foreach (preg_split('/\s+/', $q) as $word) {
                    if ($word === "")
                        continue;
                    if ($word[0] === "#" && preg_match('/\A#\d+(?:-#?\d+)?/', $word))
                        $word = substr($word, 1);
                    if (ctype_digit($word))
                        $a[$word] = (int) $word;
                    else if (preg_match('/\A(\d+)-#?(\d+)\z/s', $word, $m)) {
                        foreach (range($m[1], $m[2]) as $num)
                            $a[$num] = $num;
                    } else
                        return null;
                }
                return array_values($a);
            } else if (preg_match('/\A(\w+):"?([^"\s]+)"?\z/', $q, $m)
                       && @self::$_keywords[$m[1]] === "ss") {
                $q = self::_expand_saved_search($m[2], $ss_recursion);
                $ss_recursion[$m[1]] = true;
            } else
                return null;
        }
    }

    function alternate_query() {
        if ($this->q !== "" && $this->q[0] !== "#"
            && preg_match('/\A' . TAG_REGEX . '\z/', $this->q)) {
            if ($this->q[0] === "~")
                return "#" . $this->q;
            $result = Dbl::qe("select paperId from PaperTag where tag=? limit 1", $this->q);
            if (count(Dbl::fetch_first_columns($result)))
                return "#" . $this->q;
        }
        return false;
    }

    function has_sort() {
        return count($this->orderTags)
            || $this->numbered_papers() !== null
            || @$this->sorters;
    }

    function paperList() {
        if ($this->_matches === null)
            $this->_search();
        return $this->_matches ? : array();
    }

    function url_site_relative_raw($q = null) {
        $url = $this->urlbase;
        if ($q === null)
            $q = $this->q;
        if ($q !== "" || substr($this->urlbase, 0, 6) === "search")
            $url .= (strpos($url, "?") === false ? "?" : "&")
                . "q=" . urlencode($q);
        return $url;
    }

    function reviewer() {
        if (is_object($this->_reviewer))
            return $this->_reviewer;
        else if ($this->_reviewer)
            return Contact::find_by_id($this->_reviewer);
        else
            return null;
    }

    function reviewer_cid() {
        if (is_object($this->_reviewer))
            return $this->_reviewer->contactId;
        else if ($this->_reviewer)
            return $this->_reviewer;
        else
            return 0;
    }

    private function _tag_description() {
        if ($this->q === "")
            return false;
        else if (strlen($this->q) <= 24)
            return htmlspecialchars($this->q);
        else if (!preg_match(',\A(#|-#|tag:|-tag:|notag:|order:|rorder:)(.*)\z,', $this->q, $m))
            return false;
        $tagger = new Tagger($this->contact);
        if (!$tagger->check($m[2]))
            return false;
        else if ($m[1] === "-tag:")
            return "no" . substr($this->q, 1);
        else
            return $this->q;
    }

    function description($listname) {
        if (!$listname) {
            $a = array("s" => "Submitted papers", "acc" => "Accepted papers",
                       "act" => "Active papers", "all" => "All papers",
                       "r" => "Your reviews", "a" => "Your submissions",
                       "rout" => "Your incomplete reviews",
                       "req" => "Your review requests",
                       "reqrevs" => "Your review requests",
                       "rable" => "Reviewable papers");
            if (isset($a[$this->limitName]))
                $listname = $a[$this->limitName];
            else
                $listname = "Papers";
        }
        if ($this->q === "")
            return $listname;
        if (($td = $this->_tag_description())) {
            if ($listname === "Submitted papers") {
                if ($this->q === "re:me")
                    return "Your reviews";
                else
                    return $td;
            } else
                return "$td in $listname";
        } else {
            $listname = preg_replace("/s\\z/", "", $listname);
            return "$listname search";
        }
    }

    function listId($sort = "") {
        return "p/" . $this->limitName . "/" . urlencode($this->q)
            . "/" . ($sort ? $sort : "");
    }

    function create_session_list_object($ids, $listname, $sort = "") {
        $l = SessionList::create($this->listId($sort), $ids,
                                 $this->description($listname),
                                 $this->url_site_relative_raw());
        if ($this->matchPreg)
            $l->matchPreg = $this->matchPreg;
        return $l;
    }

    function session_list_object($sort = null) {
        return $this->create_session_list_object($this->paperList(),
                                                 null, $sort);
    }

    static function search_types($me) {
        global $Conf;
        $tOpt = array();
        if ($me->isPC && $Conf->can_pc_see_all_submissions())
            $tOpt["act"] = "Active papers";
        if ($me->isPC)
            $tOpt["s"] = "Submitted papers";
        if ($me->isPC && $Conf->timePCViewDecision(false) && $Conf->setting("paperacc") > 0)
            $tOpt["acc"] = "Accepted papers";
        if ($me->privChair)
            $tOpt["all"] = "All papers";
        if ($me->privChair && !$Conf->can_pc_see_all_submissions()
            && defval($_REQUEST, "t") === "act")
            $tOpt["act"] = "Active papers";
        if ($me->is_reviewer())
            $tOpt["r"] = "Your reviews";
        if ($me->has_outstanding_review()
            || ($me->is_reviewer() && defval($_REQUEST, "t") === "rout"))
            $tOpt["rout"] = "Your incomplete reviews";
        if ($me->isPC)
            $tOpt["req"] = "Your review requests";
        if ($me->isPC && $Conf->setting("paperlead") > 0
            && $me->is_discussion_lead())
            $tOpt["lead"] = "Your discussion leads";
        if ($me->isPC && $Conf->setting("papermanager") > 0
            && ($me->privChair || $me->is_manager()))
            $tOpt["manager"] = "Papers you administer";
        if ($me->is_author())
            $tOpt["a"] = "Your submissions";
        return $tOpt;
    }

    static function manager_search_types($me) {
        global $Conf;
        if ($me->privChair) {
            if ($Conf->has_managed_submissions())
                $tOpt = array("manager" => "Papers you administer",
                              "unm" => "Unmanaged submissions",
                              "s" => "All submissions");
            else
                $tOpt = array("s" => "Submitted papers");
            $tOpt["acc"] = "Accepted papers";
            $tOpt["und"] = "Undecided papers";
            $tOpt["all"] = "All papers";
        } else
            $tOpt = array("manager" => "Papers you administer");
        return $tOpt;
    }

    static function searchTypeSelector($tOpt, $type, $tabindex) {
        if (count($tOpt) > 1) {
            $sel_opt = array();
            foreach ($tOpt as $k => $v) {
                if (count($sel_opt) && $k === "a")
                    $sel_opt["xxxa"] = null;
                if (count($sel_opt) && ($k === "lead" || $k === "r") && !isset($sel_opt["xxxa"]))
                    $sel_opt["xxxb"] = null;
                $sel_opt[$k] = $v;
            }
            $sel_extra = array();
            if ($tabindex)
                $sel_extra["tabindex"] = 1;
            return Ht::select("t", $sel_opt, $type, $sel_extra);
        } else
            return current($tOpt);
    }

}
