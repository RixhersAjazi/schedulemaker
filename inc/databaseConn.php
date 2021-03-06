<?php
////////////////////////////////////////////////////////////////////////////
// DATABASE CONNECTION
//
// @author	Ben Russell (benrr101@csh.rit.edu)
//
// @file	inc/databaseConn.php
// @descrip	Provides mysql database connection for the system.
////////////////////////////////////////////////////////////////////////////


// Bring in the config data
require_once dirname(__FILE__) . "/config.php";

/**
 * Establishes a PDO database connection if errors happen output the errors.
 *
 * @return PDO $dbConn;
 */
function dbConnection()
{
    // Make a connection to the database
    global $DATABASE_SERVER, $DATABASE_USER, $DATABASE_PASS, $DATABASE_DB;
    try
    {
        $dbConn = new PDO('mysql:host=' . $DATABASE_SERVER . ';dbname= ' . $DATABASE_DB, $DATABASE_PASS, $DATABASE_PASS);
        $dbConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dbConn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $dbConn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $dbConn;
    }
    catch (PDOException $e)
    {
        // Set dbConn to null just to explicitly state we are canceling the connection
        $dbConn = null;
        die("Error!: " . $e->getMessage() . "<br/>");
    }
}

/**
 * By setting $pdo to null it terminates the PDO connection to the database
 *
 * @param PDO $pdo
 *
 * @return null
 */
function closeDB($pdo) {
    $pdo = null;
}


////////////////////////////////////////////////////////////////////////////
// FUNCTIONS
/**
 * Retrieves the meeting information for a section
 *
 * @param $sectionData array
 *                          Information about a section MUST HAVE:
 *                          title, instructor, curenroll, maxenroll,
 *                          department, course, section, section id,
 *                          type.
 *
 * @throws Exception
 * @return array
 *              A course array with all the information about the course
 */
function getMeetingInfo($sectionData) {
	// Store the course information

    $course = array(
        "title"      => $sectionData['title'],
        "instructor" => $sectionData['instructor'],
        "curenroll"  => $sectionData['curenroll'],
        "maxenroll"  => $sectionData['maxenroll'],
        "courseNum"  => "{$sectionData['department']}-{$sectionData['course']}-{$sectionData['section']}",
        "courseParentNum" => "{$sectionData['department']}-{$sectionData['course']}",
        "courseId"   => $sectionData['courseId'],
        "id"         => $sectionData['id'],
        "online"     => $sectionData['type'] == "O"
        );

    // If the course is online, then don't even bother looking for it's times
    if($course['online']) { return $course; }

    // Now we query for the times of the section
    $pdo = dbConnection();
    $query = "SELECT b.code, b.number, b.off_campus, t.room, t.day, t.start, t.end ";
    $query .= "FROM times AS t JOIN buildings AS b ON b.number=t.building ";
    $query .= "WHERE section = :section";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(":section", $sectionData['id']);
    $stmt->execute();
    while ($row = $stmt->fetch()) {
        $course["times"][] = array(
            "bldg"       => array("code"=>$row['code'], "number"=>$row['number']),
            "room"       => $row['room'],
            "day"        => $row['day'],
            "start"      => $row['start'],
            "end"        => $row['end'],
            "off_campus" => $row['off_campus'] == '1'
            );
    }

    closeDB($pdo);
    return $course;
}

/**
 * Retrieves a course based on the id of a section
 * @param	$id		int		The if of the section
 * @return 	array	The information about the section
 */
function getCourseBySectionId($id) {
    // Sanity check for the section id
    if($id == "" || !is_numeric($id)) {
        trigger_error("A valid section id was not provided");
    }

    $pdo = dbConnection();
    $query = "SELECT s.id,
                (CASE WHEN (s.title != '') THEN s.title ELSE c.title END) AS title,
                c.id AS courseId,
                s.instructor, s.curenroll, s.maxenroll, s.type, c.quarter, c.course, s.section, d.number, d.code
                FROM sections AS s
                  JOIN courses AS c ON s.course = c.id
                  JOIN departments AS d ON d.id = c.department
                WHERE s.id = :id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(":id", $id);
    $stmt->execute();
    $row = $stmt->fetch();

    if($row['quarter'] > 20130) {
        $row['department'] = $row['code'];
    } else {
        $row['department'] = $row['number'];
    }

    closeDB($pdo);
    return ($row) ? getMeetingInfo($row) : null;
}

/**
 * Retrieves a course specified by very specific descriptors. The resulting
 * array will contain all the information needed for the course: title,
 * instructor, enrollment, times[building, room, day, start, end].
 * @param	int		$term	    The quarter that the course is in
 * @param	int		$dept	    The department the course is in
 * @param	int		$courseNum	The course number
 * @param	int		$sectNum	The section number of the course
 * @throws	Exception			Thrown if a database error occurs, the course
 *								could not reliably be determined, or the course
 *								does not exist "type:msg"
 * @return	array				Course formatted into array as described above
 */
function getCourse($term, $dept, $courseNum, $sectNum) {
	// Build the query
    if($term > 20130) {
        $query = "SELECT s.id,
                    (CASE WHEN (s.title != '') THEN s.title ELSE c.title END) AS title,
                    s.instructor, s.curenroll, s.maxenroll, s.type, d.code AS department, c.course, s.section
                  FROM sections AS s
                    JOIN courses AS c ON c.id=s.course
                    JOIN departments AS d ON d.id=c.department
                  WHERE c.quarter = :term
                    AND d.code = :dept
                    AND c.course = :courseNum  AND s.section = :sectNum";
    } else {
        $query = "SELECT s.id,
                    (CASE WHEN (s.title != '') THEN s.title ELSE c.title END) AS title,
                    s.instructor, s.curenroll, s.maxenroll, s.type, d.number AS department, c.course, s.section
                  FROM sections AS s
                    JOIN courses AS c ON c.id=s.course
                    JOIN departments AS d ON d.id=c.department
                  WHERE c.quarter = :term
                    AND d.number = :dept
                    AND c.course = :courseNum AND s.section = :sectNum";
    }

    $pdo = dbConnection();

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(":term", $term);
    $stmt->bindParam(":dept", $dept);
    $stmt->bindParam(":courseNum", $courseNum);
    $stmt->execute();
    $result = $stmt->fetch();

	if(!$result) {
		throw new Exception("mysql:" . mysql_error());
	} elseif(mysql_num_rows($result) > 1) {
		throw new Exception("ambiguous:{$term}-{$dept}-{$courseNum}-{$sectNum}");
	} elseif(mysql_num_rows($result) == 0) {
		throw new Exception("objnotfound:{$term}-{$dept}-{$courseNum}-{$sectNum}");
	}

    closeDB($pdo);
    return getMeetingInfo($result);
}

/**
 * Does a query for all the terms in the database and parses them like
 * term:'Spring ####' for display val.
 * @return the array of terms
 */
function getTerms() {

    $pdo = dbConnection();
	$terms = array();

	// Query the database for the quarters
	$query = "SELECT quarter FROM quarters ORDER BY quarter DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute();

	// Output the quarters as options
	$curYear = 0;
	$termGroupName = "";

	while($row = $stmt->fetch()) {
		$term = $row['quarter'];

		// Parse it into a year-quarter thingy
		$year = (int) substr(strval($term), 0, 4);
		$nextYear = $year + 1;
		$useYear = $year;
		$termNum = substr(strval($term), -1);
		if($year >= 2013) {
			switch($termNum) {
				case 1: $termName = "Fall"; break;
				case 3: $termName = "Winter Intersession"; $useYear = $nextYear; break;
				case 5: $termName = "Spring"; $useYear = $nextYear; break;
				case 8: $termName = "Summer"; $useYear = $nextYear; break;
				default: $termName = "Unknown";
			}
		} else {
			switch($termNum) {
				case 1: $termName = "Fall"; break;
				case 2: $termName = "Winter"; break;
				case 3: $termName = "Spring"; $useYear = $nextYear; break;
				case 4: $termName = "Summer"; $useYear = $nextYear; break;
				default: $termName = "Unknown";
			}
		}

		if($curYear != $year) {
			$curYear = $year;
			$termGroupName = "{$year} - {$nextYear}";
		}

		// Now add it to the array
		$terms[] = array(
			"value" => (int) $term,
			"name" => "{$termName} {$useYear}",
			"group" => $termGroupName
		);
	}

    closeDB($pdo);
    return $terms;
}
