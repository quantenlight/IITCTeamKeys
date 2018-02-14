<?php
// connect to database
$dbFile = "teamkeys.db";
$db = require_once getenv("PHPLIB") . "db.php";
$url = $_SERVER["HTTP_REFERER"];
if (strpos($url, "www") !== false) {
	header("Access-Control-Allow-Origin: https://www.ingress.com");
} else {
	header("Access-Control-Allow-Origin: https://ingress.com");
}
// only accept requests directly from the Intel page
if (strpos($url, "https://") !== false && strpos($url, "ingress.com/") !== false) {
    switch ($_POST["action"]) {
        // download an entire list of keys (optionally submit a new set of keys to upload)
        case "sync":
            // check auth
            if ($_POST["user"] && $_POST["team"]) {
                $user = $_POST["user"];
                $team = $_POST["team"];
                if ($db->has("teams", array("AND" => array("user" => $user, "team" => $team)))) {
                    // submitted new keys to upload
                    if (isset($_POST["keys"])) {
                        // insert or update current keys
                        $existkeys = array();
                        foreach ($_POST["keys"] as $portal => $count) {
                            $result = $db->select("keys", ["id"], array("AND" => array("portal" => $portal, "user" => $user, "team" => $team)));
                            if (count($result) === 0) {
                                $db->insert("keys", array("user" => $user, "team" => $team, "portal" => $portal, "count" => $count));
                            } else {
                                $db->update("keys", array("count" => $count), array("id" => $result[0]["id"]));
                            }
                            array_push($existkeys, $portal);
                        }
                        // remove keys which no longer exist
                        $result = $db->select("keys", ["portal"], array("AND" => array("user" => $user, "team" => $team)));
                        foreach ($result as $row) {
                            if (!in_array($row["portal"], $existkeys)) {
                                $db->delete("keys", array("portal" => $row["portal"], "user" => $user, "team" => $team));
                            }
                        }
                    }
                    // fetch all team keys
                    $result = $db->select("keys", "*", array("team" => $team));
                    $out = '{"auth": true, "count": ' . count($result);
                    if (count($result)) {
                        $out .= ', "keys": [';
                        $i = 0;
                        foreach ($result as $row) {
                            $out .= '{"user": "' . $row["user"] . '", "portal": "' . $row["portal"] . '", "count": ' . $row["count"] . '}';
                            $i++;
                            if ($i < count($result)) {
                                $out .= ', ';
                            }
                        }
                        $out .= ']';
                    }
                    $out .= '}';
                    /* {
                        auth,
                        count,
                        keys: [
                            {user, portal, count},
                            ...
                        ]
                    } */
                    print($out);
                } else {
                    print('{"auth": false}');
                }
            } else {
                print('{"auth": false}');
            }
            break;
        // upload a cache of portals and users, add to team's global cache
        case "cache":
            // if a new cache is included
            if ($_POST["cache"]) {
                $cache = explode("\n", $_POST["cache"]);
                /* [
                    key|value,
                    ...
                ] */
                foreach ($cache as $i => $str) {
                    list($key, $value) = explode("|", $str);
                    // if not in cache already, or different value, add/update it
                    $result = $db->select("cache", "*", array("key" => $key));
                    if (count($result) === 0) {
                        $db->insert("cache", array("key" => $key, "value" => $value));
                    } elseif ($value !== $result[0]["value"]) {
                        $db->update("cache", array("value" => $value), array("key" => $key));
                    }
                }
                // return new cache
                $result = $db->select("cache", "*");
                $out = '{"count": ' . count($result);
                if (count($result)) {
                    $out .= ', "cache": {';
                    $i = 0;
                    foreach ($result as $row) {
                        $out .= '"' . $row["key"] . '": "' . addcslashes($row["value"], '"\\/') . '"';
                        $i++;
                        if ($i < count($result)) {
                            $out .= ', ';
                        }
                    }
                    $out .= '}';
                }
                $out .= '}';
                /* {
                    count,
                    cache: {
                        key: value,
                        ...
                    }
                } */
                print($out);
            } else {
                print('{"auth": false}');
            }
            break;
        // return a list of available teams to join
        case "teams":
            // check auth
            if ($_POST["user"]) {
                $user = $_POST["user"];
                // fetch all teams
                $result = $db->select("teams", "*", array("user" => $user));
                $out = '{"auth": true, "count": ' . count($result);
                if (count($result)) {
                    $out .= ', "teams": [';
                    $i = 0;
                    foreach ($result as $row) {
                        $out .= '{"team": "' . $row["team"] . '", "role": ' . $row["role"] . '}';
                        $i++;
                        if ($i < count($result)) {
                            $out .= ', ';
                        }
                    }
                    $out .= ']';
                }
                $out .= '}';
                /* {
                    count,
                    teams: [
                        {team, role},
                        ...
                    ]
                } */
                print($out);
            } else {
                print('{"auth": false}');
            }
            break;
        // mods only: fetch a list of all members of the team (optionally submit a new list)
        case "members":
            // check auth
            if ($_POST["user"] && $_POST["team"]) {
                $user = $_POST["user"];
                $team = $_POST["team"];
                // requires `role` = 1 (i.e. a moderator)
                if ($db->has("teams", array("AND" => array("user" => $user, "team" => $team, "role" => 1)))) {
                    // submitted new list of members
                    if (isset($_POST["members"]) || isset($_POST["mods"])) {
                        // remove existing members, except self (cannot be changed)
                        $db->delete("teams", array("AND" => array("team" => $team, "user[!]" => $user)));
                        // if a list of members
                        if ($_POST["members"]) {
                            // add all members (`role` = 0)
                            $data = array();
                            foreach ($_POST["members"] as $i => $member) {
                                array_push($data, array("user" => $member, "team" => $team, "role" => 0));
                            }
                            $db->insert("teams", $data);
                        }
                        // if a list of mods
                        if ($_POST["mods"]) {
                            // add all mods (`role` = 1)
                            $data = array();
                            foreach ($_POST["members"] as $i => $member) {
                                array_push($data, array("user" => $member, "team" => $team, "role" => 1));
                            }
                            $db->insert("teams", $data);
                        }
                    }
                    // fetch all members
                    $result = $db->select("teams", "*", array("team" => $team));
                    $members = array();
                    $mods = array();
                    // loop through members, assign to role list
                    foreach ($result as $row) {
                        switch ($row["role"]) {
                            case 0:
                                array_push($members, $row["user"]);
                                break;
                            case 1:
                                array_push($mods, $row["user"]);
                                break;
                        }
                    }
                    $out = '{"auth": true, "count": ' . count($result);
                    if (count($result)) {
                        $out .= ', "members": [';
                        foreach ($members as $i => $user) {
                            $out .= '"' . $user . '"';
                            if ($i + 1 < count($members)) {
                                $out .= ', ';
                            }
                        }
                        $out .= '], "mods": [';
                        foreach ($mods as $i => $user) {
                            $out .= '"' . $user . '"';
                            if ($i + 1 < count($mods)) {
                                $out .= ', ';
                            }
                        }
                        $out .= ']';
                    }
                    $out .= '}';
                    /* {
                        auth,
                        count,
                        members: [user, ...],
                        mods: [user, ...]
                    } */
                    print($out);
                } else {
                    print('{"auth": false}');
                }
            } else {
                print('{"auth": false}');
            }
            break;
    }
// redirect to Intel page if request does not originate from there
} else {
    header("Location: https://www.ingress.com/intel");
}
