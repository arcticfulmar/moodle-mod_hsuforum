<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package mod-forum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/hsuforum/lib.php');
require_once($CFG->libdir . '/rsslib.php');

$id = optional_param('id', 0, PARAM_INT);                   // Course id
$subscribe = optional_param('subscribe', null, PARAM_INT);  // Subscribe/Unsubscribe all forums

$url = new moodle_url('/mod/hsuforum/index.php', array('id'=>$id));
if ($subscribe !== null) {
    require_sesskey();
    $url->param('subscribe', $subscribe);
}
$PAGE->set_url($url);

if ($id) {
    if (! $course = $DB->get_record('course', array('id' => $id))) {
        print_error('invalidcourseid');
    }
} else {
    $course = get_site();
}

require_course_login($course);
$PAGE->set_pagelayout('incourse');
$coursecontext = get_context_instance(CONTEXT_COURSE, $course->id);


unset($SESSION->fromdiscussion);

add_to_log($course->id, 'forum', 'view forums', "index.php?id=$course->id");

$strforums       = get_string('forums', 'hsuforum');
$strforum        = get_string('forum', 'hsuforum');
$strdescription  = get_string('description');
$strdiscussions  = get_string('discussions', 'hsuforum');
$strsubscribed   = get_string('subscribed', 'hsuforum');
$strunreadposts  = get_string('unreadposts', 'hsuforum');
$strtracking     = get_string('tracking', 'hsuforum');
$strmarkallread  = get_string('markallread', 'hsuforum');
$strtrackforum   = get_string('trackforum', 'hsuforum');
$strnotrackforum = get_string('notrackforum', 'hsuforum');
$strsubscribe    = get_string('subscribe', 'hsuforum');
$strunsubscribe  = get_string('unsubscribe', 'hsuforum');
$stryes          = get_string('yes');
$strno           = get_string('no');
$strrss          = get_string('rss');

$searchform = hsuforum_search_form($course);


// Start of the table for General Forums

$generaltable = new html_table();
$generaltable->head  = array ($strforum, $strdescription, $strdiscussions);
$generaltable->align = array ('left', 'left', 'center');

if ($usetracking = hsuforum_tp_can_track_forums()) {
    $untracked = hsuforum_tp_get_untracked_forums($USER->id, $course->id);

    $generaltable->head[] = $strunreadposts;
    $generaltable->align[] = 'center';

    $generaltable->head[] = $strtracking;
    $generaltable->align[] = 'center';
}

$subscribed_forums = hsuforum_get_subscribed_forums($course);

$can_subscribe = is_enrolled($coursecontext);
if ($can_subscribe) {
    $generaltable->head[] = $strsubscribed;
    $generaltable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->hsuforum_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->hsuforum_enablerssfeeds)) {
    $generaltable->head[] = $strrss;
    $generaltable->align[] = 'center';
}

$usesections = course_format_uses_sections($course->format);
$sections = get_all_sections($course->id);

$table = new html_table();

// Parse and organise all the forums.  Most forums are course modules but
// some special ones are not.  These get placed in the general forums
// category with the forums in section 0.

$forums = $DB->get_records('forum', array('course' => $course->id));

$generalforums  = array();
$learningforums = array();
$modinfo =& get_fast_modinfo($course);

if (!isset($modinfo->instances['forum'])) {
    $modinfo->instances['forum'] = array();
}

foreach ($modinfo->instances['forum'] as $forumid=>$cm) {
    if (!$cm->uservisible or !isset($forums[$forumid])) {
        continue;
    }

    $forum = $forums[$forumid];

    if (!$context = get_context_instance(CONTEXT_MODULE, $cm->id)) {
        continue;   // Shouldn't happen
    }

    if (!has_capability('mod/forum:viewdiscussion', $context)) {
        continue;
    }

    // fill two type array - order in modinfo is the same as in course
    if ($forum->type == 'news' or $forum->type == 'social') {
        $generalforums[$forum->id] = $forum;

    } else if ($course->id == SITEID or empty($cm->sectionnum)) {
        $generalforums[$forum->id] = $forum;

    } else {
        $learningforums[$forum->id] = $forum;
    }
}

/// Do course wide subscribe/unsubscribe
if (!is_null($subscribe) and !isguestuser()) {
    foreach ($modinfo->instances['forum'] as $forumid=>$cm) {
        $forum = $forums[$forumid];
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
        $cansub = false;

        if (has_capability('mod/forum:viewdiscussion', $modcontext)) {
            $cansub = true;
        }
        if ($cansub && $cm->visible == 0 &&
            !has_capability('mod/forum:managesubscriptions', $modcontext))
        {
            $cansub = false;
        }
        if (!hsuforum_is_forcesubscribed($forum)) {
            $subscribed = hsuforum_is_subscribed($USER->id, $forum);
            if ((has_capability('moodle/course:manageactivities', $coursecontext, $USER->id) || $forum->forcesubscribe != HSUFORUM_DISALLOWSUBSCRIBE) && $subscribe && !$subscribed && $cansub) {
                hsuforum_subscribe($USER->id, $forumid);
            } else if (!$subscribe && $subscribed) {
                hsuforum_unsubscribe($USER->id, $forumid);
            }
        }
    }
    $returnto = hsuforum_go_back_to("index.php?id=$course->id");
    $shortname = format_string($course->shortname, true, array('context' => get_context_instance(CONTEXT_COURSE, $course->id)));
    if ($subscribe) {
        add_to_log($course->id, 'forum', 'subscribeall', "index.php?id=$course->id", $course->id);
        redirect($returnto, get_string('nowallsubscribed', 'forum', $shortname), 1);
    } else {
        add_to_log($course->id, 'forum', 'unsubscribeall', "index.php?id=$course->id", $course->id);
        redirect($returnto, get_string('nowallunsubscribed', 'forum', $shortname), 1);
    }
}

/// First, let's process the general forums and build up a display

if ($generalforums) {
    foreach ($generalforums as $forum) {
        $cm      = $modinfo->instances['forum'][$forum->id];
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);

        $count = hsuforum_count_discussions($forum, $cm, $course);

        if ($usetracking) {
            if ($forum->trackingtype == HSUFORUM_TRACKING_OFF) {
                $unreadlink  = '-';
                $trackedlink = '-';

            } else {
                if (isset($untracked[$forum->id])) {
                        $unreadlink  = '-';
                } else if ($unread = hsuforum_tp_count_hsuforum_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$forum->id.'">'.$unread.'</a>';
                    $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                   $forum->id.'&amp;mark=read"><img src="'.$OUTPUT->pix_url('t/clear') . '" alt="'.$strmarkallread.'" /></a></span>';
                } else {
                    $unreadlink = '<span class="read">0</span>';
                }

                if ($forum->trackingtype == HSUFORUM_TRACKING_ON) {
                    $trackedlink = $stryes;

                } else {
                    $aurl = new moodle_url('/mod/hsuforum/settracking.php', array('id'=>$forum->id));
                    if (!isset($untracked[$forum->id])) {
                        $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotrackforum));
                    } else {
                        $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtrackforum));
                    }
                }
            }
        }

        $forum->intro = shorten_text(format_module_intro('forum', $forum, $cm->id), $CFG->hsuforum_shortpost);
        $forumname = format_string($forum->name, true);;

        if ($cm->visible) {
            $style = '';
        } else {
            $style = 'class="dimmed"';
        }
        $forumlink = "<a href=\"view.php?f=$forum->id\" $style>".format_string($forum->name,true)."</a>";
        $discussionlink = "<a href=\"view.php?f=$forum->id\" $style>".$count."</a>";

        $row = array ($forumlink, $forum->intro, $discussionlink);
        if ($usetracking) {
            $row[] = $unreadlink;
            $row[] = $trackedlink;    // Tracking.
        }

        if ($can_subscribe) {
            if ($forum->forcesubscribe != HSUFORUM_DISALLOWSUBSCRIBE) {
                $row[] = hsuforum_get_subscribe_link($forum, $context, array('subscribed' => $stryes,
                        'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                        'cantsubscribe' => '-'), false, false, true, $subscribed_forums);
            } else {
                $row[] = '-';
            }
        }

        //If this forum has RSS activated, calculate it
        if ($show_rss) {
            if ($forum->rsstype and $forum->rssarticles) {
                //Calculate the tooltip text
                if ($forum->rsstype == 1) {
                    $tooltiptext = get_string('rsssubscriberssdiscussions', 'hsuforum');
                } else {
                    $tooltiptext = get_string('rsssubscriberssposts', 'hsuforum');
                }

                if (!isloggedin() && $course->id == SITEID) {
                    $userid = guest_user()->id;
                } else {
                    $userid = $USER->id;
                }
                //Get html code for RSS link
                $row[] = rss_get_link($context->id, $userid, 'mod_forum', $forum->id, $tooltiptext);
            } else {
                $row[] = '&nbsp;';
            }
        }

        $generaltable->data[] = $row;
    }
}


// Start of the table for Learning Forums
$learningtable = new html_table();
$learningtable->head  = array ($strforum, $strdescription, $strdiscussions);
$learningtable->align = array ('left', 'left', 'center');

if ($usetracking) {
    $learningtable->head[] = $strunreadposts;
    $learningtable->align[] = 'center';

    $learningtable->head[] = $strtracking;
    $learningtable->align[] = 'center';
}

if ($can_subscribe) {
    $learningtable->head[] = $strsubscribed;
    $learningtable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->hsuforum_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->hsuforum_enablerssfeeds)) {
    $learningtable->head[] = $strrss;
    $learningtable->align[] = 'center';
}

/// Now let's process the learning forums

if ($course->id != SITEID) {    // Only real courses have learning forums
    // 'format_.'$course->format only applicable when not SITEID (format_site is not a format)
    $strsectionname  = get_string('sectionname', 'format_'.$course->format);
    // Add extra field for section number, at the front
    array_unshift($learningtable->head, $strsectionname);
    array_unshift($learningtable->align, 'center');


    if ($learningforums) {
        $currentsection = '';
            foreach ($learningforums as $forum) {
            $cm      = $modinfo->instances['forum'][$forum->id];
            $context = get_context_instance(CONTEXT_MODULE, $cm->id);

            $count = hsuforum_count_discussions($forum, $cm, $course);

            if ($usetracking) {
                if ($forum->trackingtype == HSUFORUM_TRACKING_OFF) {
                    $unreadlink  = '-';
                    $trackedlink = '-';

                } else {
                    if (isset($untracked[$forum->id])) {
                        $unreadlink  = '-';
                    } else if ($unread = hsuforum_tp_count_hsuforum_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$forum->id.'">'.$unread.'</a>';
                        $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                       $forum->id.'&amp;mark=read"><img src="'.$OUTPUT->pix_url('t/clear') . '" alt="'.$strmarkallread.'" /></a></span>';
                    } else {
                        $unreadlink = '<span class="read">0</span>';
                    }

                    if ($forum->trackingtype == HSUFORUM_TRACKING_ON) {
                        $trackedlink = $stryes;

                    } else {
                        $aurl = new moodle_url('/mod/hsuforum/settracking.php', array('id'=>$forum->id));
                        if (!isset($untracked[$forum->id])) {
                            $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotrackforum));
                        } else {
                            $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtrackforum));
                        }
                    }
                }
            }

            $forum->intro = shorten_text(format_module_intro('forum', $forum, $cm->id), $CFG->hsuforum_shortpost);

            if ($cm->sectionnum != $currentsection) {
                $printsection = get_section_name($course, $sections[$cm->sectionnum]);
                if ($currentsection) {
                    $learningtable->data[] = 'hr';
                }
                $currentsection = $cm->sectionnum;
            } else {
                $printsection = '';
            }

            $forumname = format_string($forum->name,true);;

            if ($cm->visible) {
                $style = '';
            } else {
                $style = 'class="dimmed"';
            }
            $forumlink = "<a href=\"view.php?f=$forum->id\" $style>".format_string($forum->name,true)."</a>";
            $discussionlink = "<a href=\"view.php?f=$forum->id\" $style>".$count."</a>";

            $row = array ($printsection, $forumlink, $forum->intro, $discussionlink);
            if ($usetracking) {
                $row[] = $unreadlink;
                $row[] = $trackedlink;    // Tracking.
            }

            if ($can_subscribe) {
                if ($forum->forcesubscribe != HSUFORUM_DISALLOWSUBSCRIBE) {
                    $row[] = hsuforum_get_subscribe_link($forum, $context, array('subscribed' => $stryes,
                        'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                        'cantsubscribe' => '-'), false, false, true, $subscribed_forums);
                } else {
                    $row[] = '-';
                }
            }

            //If this forum has RSS activated, calculate it
            if ($show_rss) {
                if ($forum->rsstype and $forum->rssarticles) {
                    //Calculate the tolltip text
                    if ($forum->rsstype == 1) {
                        $tooltiptext = get_string('rsssubscriberssdiscussions', 'hsuforum');
                    } else {
                        $tooltiptext = get_string('rsssubscriberssposts', 'hsuforum');
                    }
                    //Get html code for RSS link
                    $row[] = rss_get_link($context->id, $USER->id, 'mod_forum', $forum->id, $tooltiptext);
                } else {
                    $row[] = '&nbsp;';
                }
            }

            $learningtable->data[] = $row;
        }
    }
}


/// Output the page
$PAGE->navbar->add($strforums);
$PAGE->set_title("$course->shortname: $strforums");
$PAGE->set_heading($course->fullname);
$PAGE->set_button($searchform);
echo $OUTPUT->header();

if (!isguestuser() && isloggedin()) {
    echo $OUTPUT->box_start('subscription');
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/hsuforum/index.php', array('id'=>$course->id, 'subscribe'=>1, 'sesskey'=>sesskey())),
            get_string('allsubscribe', 'hsuforum')),
        array('class'=>'helplink'));
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/hsuforum/index.php', array('id'=>$course->id, 'subscribe'=>0, 'sesskey'=>sesskey())),
            get_string('allunsubscribe', 'hsuforum')),
        array('class'=>'helplink'));
    echo $OUTPUT->box_end();
    echo $OUTPUT->box('&nbsp;', 'clearer');
}

if ($generalforums) {
    echo $OUTPUT->heading(get_string('generalforums', 'hsuforum'));
    echo html_writer::table($generaltable);
}

if ($learningforums) {
    echo $OUTPUT->heading(get_string('learningforums', 'hsuforum'));
    echo html_writer::table($learningtable);
}

echo $OUTPUT->footer();

