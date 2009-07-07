<?php 

/**
 * @package blog
 */

/**
 * Blog tree allows a tree of Blog Holders. Viewing branch nodes shows all blog entries from all blog holder children
 */

class BlogTree extends Page {
	
	static $db = array(
		'Name' => 'Varchar',
		'InheritSideBar' => 'Boolean',
		'LandingPageFreshness' => 'Varchar',
	);
	
	static $defaults = array(
		'InheritSideBar' => True
	);
	
	static $has_one = array(
		"SideBar" => "WidgetArea",
	);
	
	static $allowed_children = array(
		'BlogTree', 'BlogHolder'
	);

	/*
	 * Finds the BlogTree object most related to the current page.
	 * - If this page is a BlogTree, use that
	 * - If this page is a BlogEntry, use the parent Holder
	 * - Otherwise, try and find a 'top-level' BlogTree
	 */
	static function current() {
		$page = Director::currentPage();
		
		// If we _are_ a BlogTree, use us
		if ($page instanceof BlogTree) return $page;
		
		// Or, if we a a BlogEntry underneath a BlogTree, use our parent
		if ($page->is_a("BlogEntry")) {
			$parent = $page->getParent();
			if ($parent instanceof BlogTree) return $parent;
		}
		
		// Try to find a top-level BlogTree
		if(defined('Database::USE_ANSI_SQL')) {
			$top = DataObject::get_one('BlogTree', "\"ParentID\" = '0'");
		} else {
			$top = DataObject::get_one('BlogTree', 'ParentID = 0');
		}
		
		if ($top) return $top;
		
		// Try to find any BlogTree that is not inside another BlogTree
		foreach(DataObject::get('BlogTree') as $tree) {
			if (!($tree->getParent() instanceof BlogTree)) return $tree;
		}
		
		// This shouldn't be possible, but assuming the above fails, just return anything you can get
		return DataObject::get_one('BlogTree');
	}

	/* ----------- ACCESSOR OVERRIDES -------------- */
	
	public function getLandingPageFreshness() {
		$freshness = $this->getField('LandingPageFreshness');
		// If we want to inherit freshness, try that first
		if ($freshness = "INHERIT" && $this->getParent()) $freshness = $this->getParent()->LandingPageFreshness;
		// If we don't have a parent, or the inherited result was still inherit, use default
		if ($freshness = "INHERIT") $freshness = '';
		
		return $freshness;
	}
	
	function SideBar() {
		if ($this->InheritSideBar && $this->getParent()) {
			if (method_exists($this->getParent(), 'SideBar')) return $this->getParent()->SideBar();
		}
		return DataObject::get_by_id('WidgetArea', $this->SideBarID);
		// @todo: This segfaults - investigate why then fix: return $this->getComponent('SideBar');
	}
	
	/* ----------- CMS CONTROL -------------- */
	
	function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->addFieldToTab("Root.Content.Main", new TextField("Name", "Name of blog"));
		$fields->addFieldToTab('Root.Content.Main', new DropdownField('LandingPageFreshness', 'When you first open the blog, how many entries should I show', array( 
 			"" => "All entries", 
			"1 MONTH" => "Last month's entries", 
			"2 MONTH" => "Last 2 months' entries", 
			"3 MONTH" => "Last 3 months' entries", 
			"4 MONTH" => "Last 4 months' entries", 
			"5 MONTH" => "Last 5 months' entries", 
			"6 MONTH" => "Last 6 months' entries", 
			"7 MONTH" => "Last 7 months' entries", 
			"8 MONTH" => "Last 8 months' entries", 
			"9 MONTH" => "Last 9 months' entries", 
			"10 MONTH" => "Last 10 months' entries", 
			"11 MONTH" => "Last 11 months' entries", 
			"12 MONTH" => "Last year's entries", 
			"INHERIT" => "Take value from parent Blog Tree"
		))); 
 	
		$fields->addFieldToTab("Root.Content.Widgets", new CheckboxField("InheritSideBar", 'Inherit Sidebar From Parent'));
		$fields->addFieldToTab("Root.Content.Widgets", new WidgetAreaEditor("SideBar"));
		
		return $fields;
	}
		
	/* ----------- New accessors -------------- */
	
	public function loadDescendantBlogHolderIDListInto(&$idList) {
		if ($children = $this->AllChildren()) {
			foreach($children as $child) {
				if (in_array($child->ID, $idList)) continue;
				
				if ($child instanceof BlogHolder) $idList[] = $child->ID;
				else                              $child->loadDescendantBlogHolderIDListInto($idList);
			}
		}
	}
	
	// Build a list of all IDs for BlogHolders that are children of us
	public function BlogHolderIDs() {
		$holderIDs = array();
		$this->loadDescendantBlogHolderIDListInto($holderIDs);
		return $holderIDs;
	}
		
	/**
	 * Get entries in this blog.
	 * @param string limit A clause to insert into the limit clause.
	 * @param string tag Only get blog entries with this tag
	 * @param string date Only get blog entries on this date - either a year, or a year-month eg '2008' or '2008-02'
	 * @param callback retrieveCallback A function to call with pagetype, filter and limit for custom blog sorting or filtering
	 * @return DataObjectSet
	 */
	public function Entries($limit = '', $tag = '', $date = '', $retrieveCallback = null) {
		$tagCheck = '';
		$dateCheck = '';
		
		if ($tag) {
			$SQL_tag = Convert::raw2sql($tag);
			if(defined('Database::USE_ANSI_SQL')) {
				$tagCheck = "AND \"BlogEntry\".\"Tags\" LIKE '%$SQL_tag%'";
			} else {
				$tagCheck = "AND `BlogEntry`.Tags LIKE '%$SQL_tag%'";
			}
		}
		
		if ($date) {
			if(strpos($date, '-')) {
				$year = (int) substr($date, 0, strpos($date, '-'));
				$month = (int) substr($date, strpos($date, '-') + 1);
				
				if($year && $month) {
					if(defined('Database::USE_ANSI_SQL')) {
						$dateCheck = "AND MONTH(\"BlogEntry\".\"Date\") = '$month' AND YEAR(\"BlogEntry\".\"Date\") = '$year'";
					} else {
						$dateCheck = "AND MONTH(`BlogEntry`.Date) = $month AND YEAR(`BlogEntry`.Date) = $year";
					}
				}
			} else {
				$year = (int) $date;
				if($year) {
					if(defined('Database::USE_ANSI_SQL')) {
						$dateCheck = "AND YEAR(\"BlogEntry\".\"Date\") = '$year'";
					} else {
						$dateCheck = "AND YEAR(`BlogEntry`.Date) = $year";
					}
				}
			}
		}
		elseif ($this->LandingPageFreshness) {
			if(defined('Database::USE_ANSI_SQL')) {
				$dateCheck = "AND \"BlogEntry\".\"Date\" > NOW() - INTERVAL " . $this->LandingPageFreshness;
			} else {
				$dateCheck = "AND `BlogEntry`.Date > NOW() - INTERVAL " . $this->LandingPageFreshness;
			}
		}
		
		// Build a list of all IDs for BlogHolders that are children of us
		$holderIDs = $this->BlogHolderIDs();
		
		// If no BlogHolders, no BlogEntries. So return false
		if (empty($holderIDs)) return false;
		
		// Otherwise, do the actual query
		if(defined('Database::USE_ANSI_SQL')) {
			$where = '"ParentID" IN (' . implode(',', $holderIDs) . ") $tagCheck $dateCheck";
		} else {
			$where = 'ParentID IN (' . implode(',', $holderIDs) . ") $tagCheck $dateCheck";
		}

		if(defined('Database::USE_ANSI_SQL')) {
			$order = '"BlogEntry"."Date" DESC';
		} else {
			$order = '`BlogEntry`.`Date` DESC';
		}

		// By specifying a callback, you can alter the SQL, or sort on something other than date.
		if ($retrieveCallback) return call_user_func($retrieveCallback, 'BlogEntry', $where, $limit);
		else return DataObject::get('BlogEntry', $where, $order, '', $limit);
	}
}

class BlogURL {
	static function tag() {
		if (Director::urlParam('Action') == 'tag') return Director::urlParam('ID');
		return '';
	}

	static function date() {
		$year = Director::urlParam('Action');
		$month = Director::urlParam('ID');
		
		if ($month && is_numeric($month) && $month >= 1 && $month <= 12 && is_numeric($year)) {
			return "$year-$month";
		} 
		elseif (is_numeric($year)) {
			return $year;
		}
		
		return '';
	}
}

class BlogTree_Controller extends Page_Controller {
	static $allowed_actions = array(
		'rss' => true,
	);
	
	function init() {
		parent::init();
		
		// This will create a <link> tag point to the RSS feed
		RSSFeed::linkToFeed($this->Link() . "rss", _t('BlogHolder.RSSFEED',"RSS feed of these blogs"));
		Requirements::themedCSS("blog");
	}

	function BlogEntries($limit = 10) {
		$start = isset($_GET['start']) ? (int) $_GET['start'] : 0;
		return $this->Entries("$start,$limit", BlogURL::tag(), BlogURL::date());
	}

	/*
	 * @todo: It doesn't look like these are used. Remove if no-one complains - Hamish
	
	/**
	 * Gets the archived blogs for a particular month or year, in the format /year/month/ eg: /2008/10/
	 * /
	function showarchive() {
		$month = addslashes($this->urlParams['ID']);
		return array(
			"Children" => DataObject::get('SiteTree', "ParentID = $this->ID AND DATE_FORMAT(`BlogEntry`.`Date`, '%Y-%M') = '$month'"),
		);		
	}

	function ArchiveMonths() {
		$months = DB::query("SELECT DISTINCT DATE_FORMAT(`BlogEntry`.`Date`, '%M') AS `Month`, DATE_FORMAT(`BlogEntry`.`Date`, '%Y') AS `Year` FROM `BlogEntry` ORDER BY `BlogEntry`.`Date` DESC");
		$output = new DataObjectSet();
		foreach($months as $month) {
			$month['Link'] = $this->Link() . "showarchive/$month[Year]-$month[Month]";
			$output->push(new ArrayData($month));
		}
		
		return $output;
	}
	
	function tag() {
		if (Director::urlParam('Action') == 'tag') {
			return array(
				'Tag' => Convert::raw2xml(Director::urlParam('ID'))
			);
		}
		return array();
	}
	*/
	
	/**
	 * Get the rss feed for this blog holder's entries
	 */
	function rss() {
		global $project_name;

		$blogName = $this->Name;
		$altBlogName = $project_name . ' blog';
		
		$entries = $this->Entries(20);

		if($entries) {
			$rss = new RSSFeed($entries, $this->Link(), ($blogName ? $blogName : $altBlogName), "", "Title", "ParsedContent");
			$rss->outputToBrowser();
		}
	}
		
	function defaultAction($action) {
		// Protection against infinite loops when an RSS widget pointing to this page is added to this page
		if(stristr($_SERVER['HTTP_USER_AGENT'], 'SimplePie')) return $this->rss();
		
		return parent::defaultAction($action);
	}	
}
