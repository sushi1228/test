<?php
/* search */
include("data_scripts/pdo_conn.php");
include("data_scripts/utils.php");

$searchTerm = trim((string) ($_GET['term'] ?? ''));
$nameData = [];

if (!empty($_GET['ipad'])) {
	// iPad check-in: only visitors with an Expected visit today (not checked in or checked out).
	$center = GetQSCenterIDByIP($pdo);
	$centerSql = ($center !== null && $center !== '') ? ' AND v.CenterID = ' . $pdo->quote((string) $center) : '';
	$like = $pdo->quote('%' . $searchTerm . '%');

	$qry = "
		SELECT
			r.VisitorID,
			CONCAT_WS(' ', r.FirstName, r.LastName) AS Vname,
			r.Email,
			r.Mobile,
			r.CompanyName,
			r.AgreementSigned,
			r.FirstName,
r.LastName,
r.EmpID,
v.VisitorType,
			DATE_FORMAT(r.AgreementLastSignedDate, '%m/%d/%Y') AS AgreementLastSignedDate,
			v.VisitID,
			v.HostID,
			v.VisitAbout,
			v.BadgeID AS visit_badge,
			v.RoomTimeID,
			CONCAT_WS(' ', u.FirstName, u.LastName) AS host_name,
			CONCAT_WS(' ', u.LastName, u.FirstName) AS host_option_label,
			rt.ClassName,
			rt.RoomName,
			qca.AreaName
		FROM Visits v
		INNER JOIN Visitors r ON r.VisitorID = v.VisitorID
		LEFT JOIN Users u ON v.HostID = u.UserID
		LEFT JOIN RoomTimes rt ON v.RoomTimeID = rt.RoomTimeID
		LEFT JOIN QSCenterArea qca ON qca.CenterID = v.CenterID AND qca.AreaID = IFNULL(v.AreaID, rt.AreaID)
		WHERE v.VisitDate = " . $pdo->quote(date('Y-m-d')) . "
			AND v.VisitStatus IN (1,3)
			{$centerSql}
			AND (r.FirstName LIKE {$like} OR r.LastName LIKE {$like})
		ORDER BY r.FirstName, r.LastName, v.VisitID";

	foreach ($pdo->query($qry) as $row) {
		$days_num = (!empty($row['AgreementLastSignedDate'])) ? Days_Numbers($row['AgreementLastSignedDate'], date('m/d/Y')) : 0;
		$rawBadge = trim((string) ($row['visit_badge'] ?? ''));
		$badge = ($rawBadge !== '' && strcasecmp($rawBadge, '_NULL_') !== 0) ? $rawBadge : '';

		$nameData[] = [
			'id' => $row['VisitorID'],
			'value' => $row['Vname'],
			'label' => $row['Vname'],
			'email' => trim((string) $row['Email']),
			'firstname' => trim((string) ($row['FirstName'] ?? '')),
			'lastname' => trim((string) ($row['LastName'] ?? '')),
			'visitortype' => trim((string) ($row['VisitorType'] ?? '')),
			'company' => trim((string) ($row['CompanyName'] ?? '')),
			'mobile' => trim((string) ($row['Mobile'] ?? '')),
			'coname' => trim((string) ($row['CompanyName'] ?? '')),
			'empid' => trim((string) ($row['EmpID'] ?? '')),
			'agrsign' => ($days_num <= 365 && !empty($row['AgreementSigned'])) ? $row['AgreementSigned'] : '',
			'agrsigndate' => $row['AgreementLastSignedDate'],
			'days' => $days_num,
			'visitid' => (int) $row['VisitID'],
			'hostid' => isset($row['HostID']) && $row['HostID'] !== null && $row['HostID'] !== ''
				? (int) $row['HostID'] : null,
			'host_name' => trim((string) ($row['host_name'] ?? '')),
			'host_option_label' => trim((string) ($row['host_option_label'] ?? '')),
			'visitabout' => trim((string) ($row['VisitAbout'] ?? '')),
			'roomtimeid' => $row['RoomTimeID'],
			'classname' => trim((string) ($row['ClassName'] ?? '')),
			'roomname' => trim((string) ($row['RoomName'] ?? '')),
			'areaname' => trim((string) ($row['AreaName'] ?? '')),
			'is_trainee' => !empty($row['RoomTimeID']),
			'badgeid' => $badge,
			'badge' => $badge,
		];
	}
} else {
	$qry = "SELECT VisitorID, CONCAT_WS(' ', FirstName, LastName) AS Vname, Email, EmpID, Mobile, CompanyName, AgreementSigned, DATE_FORMAT(AgreementLastSignedDate, '%m/%d/%Y') AS AgreementLastSignedDate FROM Visitors WHERE FirstName LIKE " . $pdo->quote('%' . $searchTerm . '%') . " OR LastName LIKE " . $pdo->quote('%' . $searchTerm . '%');
	foreach ($pdo->query($qry) as $row) {
		$days_num = (!empty($row['AgreementLastSignedDate'])) ? Days_Numbers($row['AgreementLastSignedDate'], date('m/d/Y')) : 0;
		$nameData[] = [
			'id' => $row['VisitorID'],
			'value' => $row['Vname'],
			'email' => $row['Email'],
			'empid' => $row['EmpID'],
			'mobile' => $row['Mobile'],
			'coname' => $row['CompanyName'],
			'agrsign' => ($days_num <= 365 && !empty($row['AgreementSigned'])) ? $row['AgreementSigned'] : '',
			'agrsigndate' => $row['AgreementLastSignedDate'],
			'days' => $days_num,
			'visitid' => 0,
		];
	}
}

echo json_encode($nameData, JSON_UNESCAPED_UNICODE);
