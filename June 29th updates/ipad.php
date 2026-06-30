<?php 
function redirectIpadError($message) {
    header("Location: ipad.php?err=" . urlencode($message));
    exit;
}
include ("data_scripts/pdo_conn.php"); 
include ("data_scripts/utils.php");
//IP Address QS center filter
$center = GetQSCenterIDByIP($pdo);
$center = ($center !== null && $center !== '') ? (string) $center : null;
//echo $center;
//$center = 5; //for testing - to be removed later
// Centers with Agreement
$centerWA = Array(4,5);
// if (!empty($_POST["VisitorName"]) && !empty($_POST["Email"]) && !empty($_POST["AgreementSigned"])) { // agreement removed since 11/12/19 - KTCG only since 12/4/19
if (!empty($_POST["FirstName"]) || !empty($_POST["LastName"]) || !empty($_POST["VisitorName"])) {
	// Debug: Log all POST data at the start
	error_log("=== IPAD.PHP START ===");
	error_log("POST data: " . print_r($_POST, true));
	// Get check in date/time 	
	$now = getdate();
	$_POST["VisitDate"] = date("m/d/Y"); $_POST["ipad"] = 1;
	$ipadVisitSaved = false;
	//CheckinTime : Can add this to go to the nearest 15min interval (up or down)
	/*$rmin  = $now['minutes']%15;
	if ($rmin > 7){
		$minutes = $now['minutes'] + (15-$rmin);
	}else{
		$minutes = $now['minutes'] - $rmin;
	}
	if ($minutes == 60) {$minutes = 0; $now['hours'] = ($now['hours']!=23) ? $now['hours']+1 : 0;}
	$_POST["CheckinTime"] = $now['hours'].":".$minutes;
	*/ // removed since 11/12/19
	$_POST["CheckinTime"] = date("H:i");

	// Host is optional on iPad check-in.
	$ipadHostId = trim((string) ($_POST["HostID"] ?? ""));
	if ($ipadHostId === '') {
		$_POST["HostID"] = '_NULL_';
	} else {
		$_POST["HostID"] = $ipadHostId;
	}
	if (!empty($center)) {
		$_POST["CenterID"] = $center;
	}

	$_POST["FirstName"] = trim((string) ($_POST["FirstName"] ?? ""));
	$_POST["LastName"] = trim((string) ($_POST["LastName"] ?? ""));
	if (($_POST["FirstName"] === '' || $_POST["LastName"] === '') && !empty($_POST["VisitorName"])) {
		$parsedName = parseVisitorFullName((string) $_POST["VisitorName"]);
		if ($parsedName !== null) {
			$_POST["FirstName"] = $parsedName['first'];
			$_POST["LastName"] = $parsedName['last'];
		}
	}
	$_POST["VisitorName"] = trim($_POST["FirstName"] . " " . $_POST["LastName"]);
	$_POST["EmpID"] = trim((string) ($_POST["EmpID"] ?? ""));
	
	$_POST["BadgeID"] = '_NULL_';

	$ipadVisitorEmail = trim((string) ($_POST["Email"] ?? ""));
	if ($ipadVisitorEmail !== '' && !filter_var($ipadVisitorEmail, FILTER_VALIDATE_EMAIL)) {
		redirectIpadError("Please enter a valid email address, or leave Email blank and enter Employee ID.");
	}
	if ($ipadVisitorEmail === '') {
		$visitorFields = GetFieldsObj($pdo, "Visitors");
		$emailAllowsNull = isset($visitorFields["Email"][0]["Null"]) && strtoupper((string) $visitorFields["Email"][0]["Null"]) === "YES";
		$_POST["Email"] = $emailAllowsNull ? '_NULL_' : ' ';
	} else {
		$_POST["Email"] = $ipadVisitorEmail;
	}

	if ($_POST["FirstName"] === '' || $_POST["LastName"] === '') {
		redirectIpadError("First Name and Last Name are required.");
	}
	if ($ipadVisitorEmail === '' && $_POST["EmpID"] === '') {
		redirectIpadError("Please enter either Email Address or Employee ID.");
	}

	if (trim((string) ($_POST["VisitAbout"] ?? "")) === '') {
		redirectIpadError("Visit reason / purpose is required.");
	}

	// Safety check: if Email/EmpID already exists for another visitor name, block check-in before updating anything.
	$nameMatches = function ($row): bool {
		return strcasecmp(trim((string) ($row['FirstName'] ?? '')), trim((string) ($_POST["FirstName"] ?? ''))) === 0
			&& strcasecmp(trim((string) ($row['LastName'] ?? '')), trim((string) ($_POST["LastName"] ?? ''))) === 0;
	};

	$emailVisitor = null;
	$empVisitor = null;
	if ($ipadVisitorEmail !== '') {
		$emailVisitor = $pdo->query(
			"SELECT VisitorID, FirstName, LastName FROM Visitors WHERE Email = " . $pdo->quote($ipadVisitorEmail) . " LIMIT 1"
		)->fetch(PDO::FETCH_ASSOC) ?: null;
	}
	if ($_POST["EmpID"] !== '') {
		$empVisitor = $pdo->query(
			"SELECT VisitorID, FirstName, LastName FROM Visitors WHERE EmpID = " . $pdo->quote($_POST["EmpID"]) . " LIMIT 1"
		)->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	if ($emailVisitor && $empVisitor && (string) $emailVisitor["VisitorID"] !== (string) $empVisitor["VisitorID"]) {
		redirectIpadError("Email Address and Employee ID belong to different visitors. Please verify the information.");
	}

	if (empty($_POST["VisitorID"])) {
		if ($emailVisitor) {
			if (!$nameMatches($emailVisitor)) {
				redirectIpadError("The entered email belongs to another visitor. Please verify the email address.");
			}
			$_POST["VisitorID"] = $emailVisitor["VisitorID"];
		} elseif ($empVisitor) {
			if (!$nameMatches($empVisitor)) {
				redirectIpadError("The entered Employee ID belongs to another visitor. Please verify the Employee ID.");
			}
			$_POST["VisitorID"] = $empVisitor["VisitorID"];
		}
	} else {
		if ($emailVisitor && (string) $emailVisitor["VisitorID"] !== (string) $_POST["VisitorID"]) {
			redirectIpadError("The entered email belongs to another visitor. Please verify the email address.");
		}
		if ($empVisitor && (string) $empVisitor["VisitorID"] !== (string) $_POST["VisitorID"]) {
			redirectIpadError("The entered Employee ID belongs to another visitor. Please verify the Employee ID.");
		}
	}
	
	if (in_array($center, $centerWA)) {
		$_POST["AgreementLastSignedDate"] = (empty($_POST["argtsign"])) ? date("m/d/Y") : $_POST["AgreementLastSignedDate"];	
		$_POST["AgreementLastSignedTime"] = date("H:i:s");
	}
	
	if (empty($_POST["VisitorID"])) { // new visitor , insert		
		//$exclude = Array("EntryDateTime", "AgreementLastSignedDate", "AgreementLastSignedTime");
		$exclude = (in_array($center, $centerWA)) ? Array("VisitorID", "EntryDateTime") : Array("VisitorID", "EntryDateTime", "AgreementLastSignedDate", "AgreementLastSignedTime") ;
		$_POST["VisitorID"] = InsertData($pdo, "Visitors", $_POST, $exclude);
		if (!empty($_POST["VisitorID"])) writeToLog($pdo,4, $_POST["VisitorName"]. " - Email: " . $_POST["Email"]);
	} else { //update Visitor Signature Agreement if not signed or signed more than 12 months
		$qryupvdata = "UPDATE Visitors SET CompanyName = "
    . $pdo->quote($_POST["CompanyName"]);
		if ($ipadVisitorEmail !== '') {
			$qryupvdata .= ", Email = " . $pdo->quote($ipadVisitorEmail);
		}
		if ($_POST["EmpID"] !== '') {
			$qryupvdata .= ", EmpID = " . $pdo->quote($_POST["EmpID"]);
		}
		$qryupvdata .= " where VisitorID = " . $pdo->quote($_POST["VisitorID"]);
		$pdo->query($qryupvdata);
		if (empty($_POST["argtsign"]) && in_array($center, $centerWA)) { // KGTC agreement/signature only
			$qryup ="UPDATE Visitors SET AgreementSigned = " .$pdo->quote($_POST["AgreementSigned"]). ", AgreementLastSignedDate = " . $pdo->quote(date("Y-m-d")) . ", 
					CompanyName = " .$pdo->quote($_POST["CompanyName"]). " ,
					AgreementLastSignedTime = ". $pdo->quote(date("H:i:s")) . " where VisitorID = " . $pdo->quote($_POST["VisitorID"]);
			if ($pdo->query($qryup)) writeToLog($pdo,18, " - Agreement signed by ".$_POST["VisitorName"]. " - Email: " . $_POST["Email"]);
		} 
		if (empty($_POST["VisitID"]) && !empty($_POST["VisitorID"])) {
			$centerForVisit = $center;
			if ($centerForVisit === null && !empty($_POST["CenterID"])) {
				$centerForVisit = (string) $_POST["CenterID"];
			}
			$centerFilterSql = ($centerForVisit !== null)
				? " AND CenterID = " . $pdo->quote($centerForVisit)
				: "";
			$qryExistingVisit = "
				SELECT VisitID
				FROM Visits
				WHERE VisitorID = " . $pdo->quote($_POST["VisitorID"]) . "
					" . $centerFilterSql . "
					AND VisitDate = " . $pdo->quote(date("Y-m-d")) . "
                    AND VisitStatus = 1
                    ORDER BY VisitID DESC
                    LIMIT 1";
			$rsExistingVisit = $pdo->query($qryExistingVisit);
			if ($rsExistingVisit) {
				$existingVisit = $rsExistingVisit->fetch(PDO::FETCH_ASSOC);
				if (!empty($existingVisit["VisitID"])) {
					$_POST["VisitID"] = $existingVisit["VisitID"];
					error_log("Resolved existing visit for check-in. Using VisitID: " . $_POST["VisitID"]);
				}
			}
		}
		
		// Only update existing VisitID if it is an expected visit.
// If it is already checked-in or checked-out, create a new visit row.
if (!empty($_POST["VisitID"])) {
    $checkVisit = $pdo->query("
        SELECT VisitStatus
        FROM Visits
        WHERE VisitID = " . $pdo->quote($_POST["VisitID"]) . "
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$checkVisit || (string)$checkVisit["VisitStatus"] !== "1") {
        $_POST["VisitID"] = "";
    }
}
		//update visit if exists
		if (!empty($_POST["VisitID"])) {
    error_log("Updating existing visit with ID: " . $_POST["VisitID"]);

    // DO NOT update CenterID - keep the original center where the visit was scheduled
    $hostIdSql = ($ipadHostId !== '')
        ? ", HostID = " . $pdo->quote($ipadHostId)
        : ", HostID = NULL";

    $qryupv = "UPDATE Visits SET ipad='1',
        CheckinTime = " . $pdo->quote($_POST["CheckinTime"]) . ",
        CheckoutTime = NULL,
        VisitStatus = '2',
        BadgeID = " . $pdo->quote($_POST["BadgeID"]) . ",
        VisitAbout = " . $pdo->quote($_POST["VisitAbout"]) .
        $hostIdSql .
        " WHERE VisitID = " . $pdo->quote($_POST["VisitID"]);

    if ($pdo->query($qryupv)) {
        $ipadVisitSaved = true;
        writeToLog($pdo, 15, "from iPad - Visit ID:" . $_POST["VisitID"]);
        error_log("Visit updated successfully");
    } else {
        error_log("ERROR: Failed to update visit");
        die("ERROR: Failed to update visit. Check error logs.");
    }

} else {
    error_log("No existing open visit found, will create new visit below");
}
	}

	// Add new Visit
	$_POST["VisitStatus"] = 2;
	if (empty($_POST["VisitID"])) {

		$exclude = Array(
    "VisitID",
    "EntryDateTime",
    "chkosystem",
    "ModifDateTime",
    "CheckoutTime",
    "AreaID",
    "RoomTimeID",
    "VisitorType"
);
		
		// Debug: Log what we're trying to insert
		error_log("=== IPAD.PHP DEBUG ===");
		error_log("Attempting to insert visit with data: " . print_r($_POST, true));
		error_log("Excluded fields: " . print_r($exclude, true));
		
		$idvisit = InsertData($pdo, "Visits", $_POST, $exclude);
		
		error_log("InsertData returned: " . var_export($idvisit, true));
		
		if (!empty($idvisit)) {
			$ipadVisitSaved = true;
			writeToLog($pdo,3, "Visit ID:" .$idvisit);
			$_POST["VisitID"] = $idvisit; // Store the new visit ID
			error_log("Visit created successfully with ID: " . $idvisit);
		} else {
			error_log("ERROR: InsertData returned empty/null/false!");
			// Don't redirect if insert failed
			die("ERROR: Failed to create visit. Check error logs.");
		}
	}

	if ($ipadVisitSaved && !empty($_POST["VisitID"])) {
		$pdo->query(
			"INSERT INTO VisitEvents (VisitID, Email, EventType) VALUES (" .
			$pdo->quote($_POST["VisitID"]) . ", " .
			$pdo->quote($ipadVisitorEmail) . ", 'IN')"
		);
	}
	
	// Check-in email: send to visitor and/or host only when each has a valid address
	$visitor_name = $_POST["VisitorName"];
	$visitor_company = $_POST["CompanyName"];
	$visit_about = $_POST["VisitAbout"];
	$visitor_email = $ipadVisitorEmail;
	$host_email = '';
	$host_name = '';
	$send_visitor_email = ($visitor_email !== '');
	$send_host_email = false;


	if ($ipadHostId !== '') {
		$qryhost = "SELECT Email, CONCAT_WS(' ', FirstName, LastName) AS host_name FROM Users WHERE UserID = " . $pdo->quote($ipadHostId);
		$rshost = $pdo->query($qryhost)->fetch(PDO::FETCH_ASSOC);
		if ($rshost) {
			$host_email = trim((string) ($rshost["Email"] ?? ""));
			$host_name = trim((string) ($rshost["host_name"] ?? ""));
			$send_host_email = ($host_email !== '' && filter_var($host_email, FILTER_VALIDATE_EMAIL));
		}
	}

	

	if (in_array($center, $centerWA) && !empty($_POST["AgreementLastSignedDate"])) {
		$agrt_date = DateTime::createFromFormat('m/d/Y', $_POST["AgreementLastSignedDate"]);
		if ($agrt_date) {
			$agree_date = $agrt_date->format('M d, Y');
		}
	}

	if ($send_visitor_email || $send_host_email) {
		ob_start();
		include ("pdf_sign_email.php");
		ob_end_clean();
	}

	header("Location: ipadchkin.php?VisitID=" . $_POST["VisitID"]);
	exit;
}

// Host dropdown: all active users for new walk-ins; center staff when existing visitor is selected
$ipadHostOptionsAll = ListUsers($pdo, 0, " where Status = '1'");
$ipadHostOptionsCenter = ($center !== null)
	? ListUsers($pdo, 0, " where Status = '1' and CenterID = " . $pdo->quote($center))
	: $ipadHostOptionsAll;

?>
<!doctype html>
<html class="fixed">
	<head>

		<!-- Basic -->
		<meta charset="UTF-8">

		<meta name="keywords" content="Visitors Management System" />
		<meta name="description" content="QS Visitors Management System">

		<!-- Mobile Metas -->
		<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
		<!-- Title -->
		<title>QS Visitors Management System</title>
		<!-- Icon -->
		<link rel="shortcut icon" href="assets/images/icon19.ico" />
		<!-- Web Fonts  -->
		<link href="http://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700,800|Shadows+Into+Light" rel="stylesheet" type="text/css">

		<!-- Vendor CSS -->
		<link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.css" />
		<link rel="stylesheet" href="assets/vendor/font-awesome/css/font-awesome.css" />
		<link rel="stylesheet" href="assets/vendor/magnific-popup/magnific-popup.css" />
		<link rel="stylesheet" href="assets/vendor/bootstrap-datepicker/css/datepicker3.css" />

		<!-- Theme CSS -->
		<link rel="stylesheet" href="assets/stylesheets/theme.css" />

		<!-- Skin CSS -->
		<link rel="stylesheet" href="assets/stylesheets/skins/default.css" />

		<!-- Theme Custom CSS -->
		<link rel="stylesheet" href="assets/stylesheets/theme-custom.css">
		<style>

</style>
		<!--  Modal CSS -->
		<link rel="stylesheet" href="assets/stylesheets/modal.css">
		<!-- Head Libs -->
		<script src="assets/vendor/modernizr/modernizr.js"></script>
		
		<!-- Jquery autocomplete -->
		<link rel="stylesheet" href="assets/stylesheets/jquery-ui.css">
		<script src="https://code.jquery.com/jquery-1.12.4.js"></script>
		<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
		
		<!--  Jquery signature -->
		<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
		<script src="http://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
		<script src="assets/javascripts/jquery.signature.js"></script>
		<script type="text/javascript" src="assets/javascripts/jquery.ui.touch-punch.min.js"></script>	
		
		<!--[if IE]>
		<script src="js/excanvas.js"></script>
		<![endif]-->
				
		<script>
			jQuery().ready(function($){
$('#FullName, #Email, #EmpID').css({
    "border-color": "#bdbdbd",
    "background-color": "#FFF"
});

$("#HostID").prop("disabled", true);

$("#FullName, #CompanyName").on("input", function () {	
    var value = $(this).val();

    $(this).val(
        value.replace(/\b\w/g, function(letter) {
            return letter.toUpperCase();
        })
    );
});
$("#CompanySelect").change(function () {

    var company = $(this).val();

    if (company === "") {
        $("#CompanyName").hide().val("");
        $("#VisitAbout").val("");
        $("#HostID").val("").prop("disabled", true);
        $("#hostLabel").html("Host");
        return;
    }

    if (company === "CUSTOM") {
        $(this).hide();

        $("#CompanyName")
            .show()
            .val("")
            .focus();

        $("#HostID").prop("disabled", false);
        $("#hostLabel").html('Host <span class="required">*</span>');

        $("#VisitAbout").val("");
        return;
    }

// Predefined company
$("#CompanyName")
    .hide()
    .val(company);

$("#HostID")
    .val("")
    .prop("disabled", true);

$("#hostLabel").html("Host");

if (company === "QS Contractor") {
    $("#VisitAbout").val("QS Instructor");
} else {
    $("#VisitAbout").val(company + " - Trainee");
}
});			
				
				function ipad_set_host_list(mode) {
					var $sel = $("#HostID");
					var saved = $sel.val();
					var $src = $("#ipad-host-options-" + (mode === "center" ? "center" : "all"));
					$sel.html($src.html());
					if (saved && $sel.find('option[value="' + saved.replace(/"/g, '\\"') + '"]').length) {
						$sel.val(saved);
					} else {
						$sel.val("");
					}
				}

				function ipad_clear_expected_visit_ui() {
				
					$("#VisitAbout").prop('readonly', false).attr('placeholder','Visit reason / purpose');
					$("#HostID option.ipad-injected-host").remove();
					$("#HostID").val("");
					if (!$("#VisitorID").val()) {
						ipad_set_host_list("all");
					}
				}

				ipad_set_host_list("all");


				/** Ensure visit host appears in & is selected on #HostID (same UserID as backend). */
				function ipad_set_host_from_visit(hostId, hostLabel) {
					if (!hostId) {
						return;
					}
					var sid = String(hostId);
					var $sel = $("#HostID");
					var hasOpt = $sel.find("option").filter(function() {
						return $(this).val() === sid;
					}).length > 0;
					if (!hasOpt && hostLabel) {
						$sel.append(
							$("<option></option>")
								.attr("value", sid)
								.addClass("ipad-injected-host")
								.text($.trim(hostLabel))
						);
					}
					$sel.val(sid);
				}

				$("#resetvisitor").click (function () {
					ipad_clear_expected_visit_ui();
					ipad_set_host_list("all");
					$("#VisitorID").val(""); 
                    $("#VisitID").val(""); 
                    $("#VisitorName").val(""); 
                    $("#FullName").val(""); 
                    $("#FirstName").val(""); 
                    $("#LastName").val(""); 
                    $("#Email").val(""); 
                    $("#EmpID").val(""); 
					$("#CompanySelect").show().val("");
					$("#HostID").val("").prop("disabled", true);
                    $("#hostLabel").html("Host");
                    $("#CompanyName").hide().val("");
                    $("#AgreementSigned").val("");
					$("#chk_ter").html("<label for=\"AgreeTerms\">Click <a href=\"#modal-text\" class=\"call-modal\" title=\"Clicking this link shows the terms of agreement\">here</a> to read and sign the terms of agreement</a> <span class=\"required\">*</span></label>");
					$("#Email").prop('readonly', false);
                    $("#FullName, #EmpID").prop('readonly', false);
					$("#errormsg").html(""); 
					$('#FullName, #Email, #EmpID').css({"border-color": "#bdbdbd","background-color": "#FFF"});
					$('#HostID').css({"border-color": "#bdbdbd", "background-color": "#FFF"});
					$('#VisitAbout').css({"border-color": "#bdbdbd", "background-color": "#FFF"});
					$("#VisitAbout").val("");
                    $("#VisitAbout").show();
					/*$("#CompanyName").prop('readonly', false); $("#Mobile").prop('readonly', false);*/
				});
				
				
				$("#FullName").autocomplete({
					minLength: 2,
					source: function(request, response) {
						$.ajax({
							url: "search_name.php",
							dataType: "json",
							data: { term: request.term, ipad: 1 },
							success: function(data) {
								response($.map(data, function(row) {
									return $.extend(true, {}, row, {
										label: row.display_name || row.label || row.value,
										value: row.value
									});
								}));
							},
							error: function() {
								response([]);
							}
						});
					},
					select: function( event, ui ) {
						var it = ui.item;
						ipad_clear_expected_visit_ui();
						$("#VisitorID").val(it.id);
						if (it.id) {
							ipad_set_host_list("center");
						} else {
							ipad_set_host_list("all");
						}
						$("#Email").val(it.email);
						$("#EmpID").val(it.empid || "");
						var companies = ["HMGMA", "Mobis", "Glovis", "Hyundai Steel", "Transys","QS Contractor"];

if ($.inArray(it.coname, companies) >= 0) {

    $("#CompanySelect")
        .show()
        .val(it.coname);

    $("#CompanyName")
        .hide()
        .val(it.coname);

    $("#HostID")
        .val("")
        .prop("disabled", true);

    $("#hostLabel").html("Host");

} else {

    $("#CompanySelect").hide();

    $("#CompanyName")
        .show()
        .val(it.coname || "")
        .attr("placeholder", "Enter Company Name");

    $("#HostID").prop("disabled", false);
    $("#hostLabel").html('Host <span class="required">*</span>');
}
						<?php if (in_array($center, $centerWA)) { ?>
							if($("#AgreementSigned").val() == "") { $("#AgreementSigned").val(it.agrsign);}
							$("#AgreementLastSignedDate").val(it.agrsigndate);
							if (it.agrsign !="") { $("#argtsign").val(1); $("#chk_ter").html("<img src='assets/images/checkr.png' /> I agree that I have read, understand and agree to all the terms of agreement.");}
						<?php } else { ?>
							$("#AgreementSigned").val("");
							$("#AgreementLastSignedDate").val("");
						<?php } ?>
						$("#VisitID").val(it.visitid || "");
						
						/* Expected visit today: host + about from admin/bulk import */
						if (it.visitid) {
							if (it.hostid) {
								ipad_set_host_from_visit(it.hostid, it.host_option_label || it.host_name);
							}
							var about = (it.visitabout !== undefined && it.visitabout !== null)
    ? String(it.visitabout)
    : "";

$("#VisitAbout").val(about);
						} else {
							$("#VisitAbout").val("");
						}

					
						if ($.trim(it.email || "") !== "") {
							$("#Email").prop('readonly', true);
						} else {
							$("#Email").prop('readonly', false);
						}
						/*$("#Mobile").prop('readonly', true);
						$("#CompanyName").prop('readonly', true);*/
						$("#FullName").val($.trim((it.firstname || "") + " " + (it.lastname || "")));

                        $("#FirstName").val(it.firstname || "");
                        $("#LastName").val(it.lastname || "");

                        $("#VisitorName").val($("#FullName").val());
						$("#FullName").prop('readonly', true).css({
							"border-color": "#bdbdbd",
							"background-color": "#FFF"
						});

                    $("#errormsg").html("");
					return false;
						// if (ui.item.agrsign !="") { $("#argtsign").val(1); $("#chk_ter").html("<img src='assets/images/checkr.png' /> I agree that I have read, understand and agree to all the terms of agreement.");} /**** signature removed since 11/12/19 *****/
					  }			
				})
				.autocomplete( "instance" )._renderItem = function( ul, item ) {
				  var details = [];
				  if ($.trim(item.email || "") !== "") { details.push($('<div/>').text(item.email).html()); }
				  if ($.trim(item.empid || "") !== "") { details.push("EmpID: " + $('<div/>').text(item.empid).html()); }
				  if ($.trim(item.coname || "") !== "") { details.push($('<div/>').text(item.coname).html()); }
				  return $( "<li>" )
					.append( "<div><span class='text-bold'>" + $('<div/>').text(item.display_name || item.label || item.value).html() + "</span>" + (details.length ? " - " + details.join(" - ") : "") + "</div>" )
					.appendTo( ul );
				};
				
				
				$("#cancel").click(function () {
					$(location).attr('href',"iPad/index.php");
				});
				
				<?php if (in_array($center, $centerWA)) { ?>
				$("#close_dialog1").click(function ()
				{
					//alert($('#sig').signature('isEmpty'));
					if ($('#sig').signature('isEmpty') == false) {						
						$("#AgreementSigned").val($('#sig').signature('toDataURL'));
						$('#chk_ter').css({"border-color": "white", "background-color": "#FFF"});
						$("#chk_ter").html("<img src='assets/images/checkr.png' /> I agree that I have read, understand and agree to all the terms of use.");
						//alert("signature:" + $("#AgreementSigned").val());
					} else  {
						$('#sig').css({"border-color": "red", "background-color": "#F79BB7"});
						$('#chk_ter').css({"border-color": "red", "background-color": "#F79BB7"});
						$("#chk_ter").html("<span class=\"required\">You have not signed the terms of agreement. Click <a href=\"#modal-text\" class=\"call-modal\" title=\"Clicking this link shows the terms of agreement\">here</a> to read and sign it <sup>*</sup></span>");
					}	
					//$('#termsStatus').html("");
				});  /* Agr. Signature removed since 11/12/19 --  only KGCT since 12/4/19 */
				<?php } ?>
			
				$("#checkin").click(function () {
					    if ($(this).data("submitted") === true) {
        return false;
    }
					var err = 0, em = 0;
					var emailReg = /^([\w-\.]+@([\w-]+\.)+[\w-]{2,4})?$/;
					var msgerr = "";
					

$('#FullName, #Email, #EmpID, #VisitAbout').css({
    "border-color": "#bdbdbd",
    "background-color": "#FFF"
});

				var fullName = $.trim($("#FullName").val());

var parts = fullName.split(" ");

var firstName = parts.shift() || "";
var lastName = parts.join(" ");

$("#FirstName").val(firstName);
$("#LastName").val(lastName);
$("#VisitorName").val(fullName);

var email1 = $.trim($("#Email").val());
var empId = $.trim($("#EmpID").val());


if (fullName === "") {
    err = 1;
    $('#FullName').css({
        "border-color": "red",
        "background-color": "#F79BB7"
    });
}

					if (email1 === "" && empId === "") {
						err = 1;
						$('#Email, #EmpID').css({"border-color": "red", "background-color": "#F79BB7"});
					}

					if (email1 !== "" && !emailReg.test(email1)) {
						em = 1;
						$('#Email').css({"border-color": "red", "background-color": "#F79BB7"});
					}

				
		            

					if ($.trim($("#VisitAbout").val()) == "") {
						err = 1;
						$('#VisitAbout').css({"border-color": "red", "background-color": "#F79BB7"});
					}

					if ($("#CompanyName").is(":visible") && $.trim($("#HostID").val()) === "") {
    err = 1;
    $('#HostID').css({
        "border-color": "red",
        "background-color": "#F79BB7"
    });
}

					if (err == 0 && em == 0) {
						var data = {
							fn: firstName,
							ln: lastName,
							vn: $.trim(firstName + " " + lastName),
							vid: $("#VisitorID").val(),
							visitid : $("#VisitID").val(),
							vdate : "<?php echo date("m/d/Y");?>",
							centeridt: $("#CenterID").val()
						};
						if (email1 !== "") { data.em = email1; }
						if (empId !== "") { data.empid = empId; }

						jQuery.ajax({
						  url: 'chkvisitdata.php',
						  type: "POST",
						  dataType: "html",
						  data:data,
						  async: false,
						  success: function(msg) {
								msg = (msg || "").trim();
								if (msg === "") {$("#checkin").data("submitted", true)
             .prop("disabled", true)
             .val("Checking In...");
									$("#fvisit").submit();
									return;
								}
								var res = msg.split("###");
								var msgr = "";
								if (res[0] != "" && res[0] != "0") {
									msgr = "The entered email or Employee ID belongs to another visitor. Please verify the information.";
									$('#Email, #EmpID').css({"border-color": "red", "background-color": "#F79BB7"});
								}
								<?php if (in_array($center, $centerWA)) { ?>
								if ($("#AgreementSigned").val() == "") { msgr += " Please accept and sign the terms of agreement."; $('#chk_ter').css({"border-color": "red", "background-color": "#F79BB7"});}
								<?php } ?>
								if (msgr != "") { $("#errormsg").html("<center>" + msgr + "</center>"); }
								else { $("#checkin").data("submitted", true)
             .prop("disabled", true)
             .val("Checking In..."); $("#fvisit").submit(); }
						  }
						});
					} else {
						if (err == 1) { msgerr = "Please fill all the required fields."; }
						if (em == 1) { msgerr += " Email field is invalid."; }
						$("#errormsg").html("<center>" + msgerr + "</center>");
					}
				});
				
				//signature
				$('#sig').signature( {
					background: '#ffffff', // Colour of the background 
					color: '#21477a', // Colour of the signature 
					thickness: 2,
					syncFormat: 'PNG',
					// Error message when no canvas 
					notAvailable: 'Your browser doesn\'t support signing'
				});
				
				$('#clear').click(function() {
					$('#sig').signature('clear');  // removed since 11/12/19 -- only KGTC since 12/4/19
				});
				
			});
		</script>

	</head>
	<body>
		<!-- start: page -->
		<section class="body-sign2">
			<div class="center-sign">
				<a href="/" class="logo pull-left">
					<img src="assets/images/logo.png" height="45" alt="QS Visitor Management System" />
				</a>

				<div class="panel panel-sign">
					<div class="panel-title-sign mt-xl text-right">
						<h2 class="title text-uppercase text-bold m-none"><i class="fa fa-user mr-xs"></i> Visitor&nbsp;&nbsp;Check In</h2>
					</div>
					<div class="panel-body">
						<div class="alert alert-info">
						<p class="m-none text-semibold h6">If you are expected today, type 2 or more characters of your first or last name and select your row from the list to display your information.</p>
						</div>
						<form name="fvisit" id="fvisit" method="post" action="ipad.php">
						<span class="text-danger">(*) required fields</span>
						<span class="text-danger" id="errormsg"></span>
						<?php if (!empty($_GET["err"])) { ?>
    <div class="alert alert-danger mt-sm" style="margin-top:10px;">
        <?php echo htmlspecialchars($_GET["err"]); ?>
    </div>
<?php } ?>
						<input type="hidden" name="VisitorID" id="VisitorID" value="" />
						<input type="hidden" name="VisitorName" id="VisitorName" value="" />
						<input type="hidden" name="CenterID" id="CenterID" value="<?php echo $center;?>" />
						<input type="hidden" name="AgreementSigned" id="AgreementSigned" value="" />
						<input type="hidden" name="AgreementLastSignedDate" id="AgreementLastSignedDate" value="" />
						<input type="hidden" name="argtsign" id="argtsign" value="" /><input type="hidden" name="VisitID" id="VisitID" value="" />
							<div class="form-group mb-none">
								<div class="row">
									<div class="col-sm-12 mb-lg">
                                    <label>First Name & Last Name <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="FullName" id="FullName" />
                                    </div>

                            <input type="hidden" name="FirstName" id="FirstName" />
                            <input type="hidden" name="LastName" id="LastName" />
								</div>
							</div>

							<div class="form-group mb-none">
								<div class="row">
									<div class="col-sm-6 mb-lg">
										<label>Email Address</label>
										<input name="Email" id="Email" type="text" class="form-control" placeholder="name@example.com" />
									</div>
									<div class="col-sm-6 mb-lg">
										<label>Employee ID</label>
										<input name="EmpID" id="EmpID" type="text" class="form-control" placeholder="Employee ID" />
										<span class="identity-help" style="color: red;">Please enter an Email Address or Employee ID.</span>
									</div>
								</div>
							</div>

							<div class="form-group mb-none">
								<div class="row">
									<div class="col-sm-6 mb-lg">
										<label>Company</label>

<select id="CompanySelect" class="form-control">
    <option value="">-- Select Company --</option>
    <option value="HMGMA">HMGMA</option>
    <option value="Mobis">Mobis</option>
    <option value="Glovis">Glovis</option>
    <option value="Hyundai Steel">Hyundai Steel</option>
    <option value="Transys">Transys</option>
	<option value="QS Contractor">QS Contractor</option>
    <option value="CUSTOM">Type Company Name</option>
</select>

<input
    name="CompanyName"
    id="CompanyName"
    type="text"
    class="form-control"
    style="display:none;"
    placeholder="Enter Company Name">
									</div>
									<div class="col-sm-6 mb-lg">
										<label id="hostLabel">Host</label>
                                        <select class="form-control" id="HostID" name="HostID">
										<?php echo $ipadHostOptionsAll; ?>
										</select>
										<div id="ipad-host-options-all" class="hidden" aria-hidden="true"><?php echo $ipadHostOptionsAll; ?></div>
										<div id="ipad-host-options-center" class="hidden" aria-hidden="true"><?php echo $ipadHostOptionsCenter; ?></div>
									</div>
								</div>
							</div>
							
						
							<label>Visit reason / purpose<span class="required">*</span></label>

                            <input name="VisitAbout" id="VisitAbout" type="text" class="form-control" placeholder="Visit reason / purpose" />
							
							<!-- signature removed since 11/12/19 -- only KGTC since 12/4/19 -->
							<?php if (in_array($center, $centerWA)) { ?>
							<div class="row">
								<div class="col-sm-12" id="chk_ter">														
										<label for="AgreeTerms">Click <a href="#modal-text" class="call-modal" title="Clicking this link shows the terms of agreement">here</a> to read and sign the terms of agreement</a> <span class="required">*</span></label>									
								</div>								
							</div> 
							<?php } ?>
							<div class="row">
								<div class="col-sm-4">									
									<input type="button" class="btn btn-default btn-block btn-lg mt-lg" id="cancel" value="Cancel">									
								</div>
								<div class="col-sm-4">									
									<input type="button" class="btn btn-dark btn-block btn-lg mt-lg" id="resetvisitor" value="Reset">									
								</div>
								<div class="col-sm-4 text-right">									
									<input type="button" class="btn btn-chkin btn-block btn-lg mt-lg" id="checkin" value="Check In">
								</div>
							</div>

						</form>
					</div>
				</div>

				<p class="text-center text-muted mt-md mb-md">&copy; <?php echo date("Y");?> Quick Start</p>
			</div>
		</section>
		<?php if (in_array($center, $centerWA)) include("modalterms{$center}.php"); /*removed since 11/12/19 -- only KGTC since 12/4/19  */?>
		<!-- end: page -->

		<!-- Vendor -->
		<script src="assets/vendor/jquery/jquery.js"></script>
		<script src="assets/vendor/jquery-browser-mobile/jquery.browser.mobile.js"></script>
		<script src="assets/vendor/bootstrap/js/bootstrap.js"></script>
		<script src="assets/vendor/nanoscroller/nanoscroller.js"></script>
		<script src="assets/vendor/bootstrap-datepicker/js/bootstrap-datepicker.js"></script>
		<script src="assets/vendor/magnific-popup/magnific-popup.js"></script>
	<script src="assets/vendor/jquery-placeholder/jquery.placeholder.js"></script>
	
	<script src="assets/vendor/jquery-maskedinput/jquery.maskedinput.js?v=2"></script>
	
	<!-- Theme Base, Components and Settings -->
		<script src="assets/javascripts/theme.js"></script>
		
		<!-- Theme Custom -->
		<script src="assets/javascripts/theme.custom.js"></script>
		
		<!-- Theme Initialization Files -->
		<script src="assets/javascripts/theme.init.js"></script>

	</body>
</html>
