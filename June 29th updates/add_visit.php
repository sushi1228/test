<?php
// Edit visit is ONLY accessible on current day
// Insert DB
//echo "Visitor Name:".$_POST["VisitorName"] . " | Email: " . $_POST["Email"];
if (!function_exists('recordAdminVisitEvents')) {
	function recordAdminVisitEvents(PDO $pdo, $visitId, string $email, int $visitStatus, string $visitDate, string $checkinTime, string $checkoutTime = ''): void
	{
		if (empty($visitId)) {
			return;
		}

		// VisitEvents is only activity history. Expected visits should not create IN/OUT rows.
		if ($visitStatus !== 2 && $visitStatus !== 3) {
			return;
		}

		$visitDateDb = $visitDate;
		if (strpos($visitDateDb, '/') !== false) {
			$visitDateDb = DateFormatDB($visitDateDb);
		}

		$checkinCreatedAt = trim($visitDateDb . ' ' . (($checkinTime !== '') ? $checkinTime : date('H:i')));

		// Add IN event once for this visit if admin created/edited it as Checked In or Checked Out.
		$inExists = (int) ($pdo->query(
			"SELECT COUNT(*) FROM VisitEvents WHERE VisitID = " . $pdo->quote($visitId) . " AND EventType = 'IN'"
		)->fetchColumn() ?: 0);

		if ($inExists === 0) {
			$pdo->query(
				"INSERT INTO VisitEvents (VisitID, Email, EventType, CreatedAt) VALUES (" .
				$pdo->quote($visitId) . ", " .
				$pdo->quote(trim($email)) . ", 'IN', " .
				$pdo->quote($checkinCreatedAt) . ")"
			);
		}

		// If admin saved as Checked Out, add OUT event once.
		if ($visitStatus === 3) {
			$outExists = (int) ($pdo->query(
				"SELECT COUNT(*) FROM VisitEvents WHERE VisitID = " . $pdo->quote($visitId) . " AND EventType = 'OUT'"
			)->fetchColumn() ?: 0);

			if ($outExists === 0) {
				$outTime = ($checkoutTime !== '') ? $checkoutTime : date('H:i');
				$outCreatedAt = trim($visitDateDb . ' ' . $outTime);
				$pdo->query(
					"INSERT INTO VisitEvents (VisitID, Email, EventType, CreatedAt) VALUES (" .
					$pdo->quote($visitId) . ", " .
					$pdo->quote(trim($email)) . ", 'OUT', " .
					$pdo->quote($outCreatedAt) . ")"
				);
			}
		}
	}
}
if (
	!empty($_POST["VisitorName"]) &&
	(!empty($_POST["Email"]) || !empty($_POST["EmpID"])) &&
	trim((string) ($_POST["VisitAbout"] ?? "")) !== ""
) {
	// Admin/manual visits default to GENERAL unless another value is explicitly posted.
	$_POST["VisitorType"] = strtoupper(trim((string) ($_POST["VisitorType"] ?? "")));
	if (!in_array($_POST["VisitorType"], ["GENERAL", "TRAINEE"], true)) {
		$_POST["VisitorType"] = "GENERAL";
	}

	//Vistor table
	$name = explode(" ", $_POST["VisitorName"]);
	$chkotime = $_POST["CheckoutTime"]; // for checkout email notification to host (cf. below)
	// CheckinTime/CheckoutTime 12 hour to 24 hour
	$_POST["CheckinTime"] = (!empty($_POST["CheckinTime"])) ? date("H:i", strtotime($_POST["CheckinTime"])) : "";
	$_POST["CheckoutTime"] = (!empty($_POST["CheckoutTime"])) ? date("H:i", strtotime($_POST["CheckoutTime"])) : "";
	if ($_POST["VisitStatus"] != 3)
		$_POST["CheckoutTime"] = "";

	if (empty($_POST["VisitorID"])) { // new visitor , insert
		$_POST["FirstName"] = $name[0];
		$_POST["LastName"] = $name[1];
		$exclude = array("VisitorID", "AgreementSigned", "AgreementLastSignedDate", "AgreementLastSignedTime", "EntryDateTime");
		$_POST["VisitorID"] = InsertData($pdo, "Visitors", $_POST, $exclude);
		writeToLog($pdo, 4, $_POST["VisitorName"] . " - Email: " . $_POST["Email"]);
	}

	if (empty($_POST["VisitID"])) {  // Add Visits
		//echo "Add visit";
		$exclude = array("VisitID", "EmpID", "RoomTimeID", "EntryDateTime", "chkosystem", "ModifDateTime", "ipad", "CourseTimeID");
		$idvisit = InsertData($pdo, "Visits", $_POST, $exclude);
		if (!empty($idvisit)) {
			recordAdminVisitEvents($pdo, $idvisit, (string) $_POST["Email"], (int) $_POST["VisitStatus"], (string) $_POST["VisitDate"], (string) $_POST["CheckinTime"], (string) $_POST["CheckoutTime"]);
		}
		writeToLog($pdo, 5, "Visit ID:" . $idvisit);
		echo Redirection("admin.php?p=list_visits&act=1");
	} else { // Edit Visits			
		//echo "Edit visit";
		$exclude = array("EmpID", "RoomTimeID", "EntryDateTime", "chkosystem", "ModifDateTime", "ipad", "CourseTimeID");
		UpdateData($pdo, "Visits", $_POST, $_POST["VisitID"], $exclude);
		recordAdminVisitEvents($pdo, $_POST["VisitID"], (string) $_POST["Email"], (int) $_POST["VisitStatus"], (string) $_POST["VisitDate"], (string) $_POST["CheckinTime"], (string) $_POST["CheckoutTime"]);
		writeToLog($pdo, 15, "Visit ID:" . $_POST["VisitID"]);
		echo Redirection("admin.php?p=list_visits&act=2");
	}

	//send notification to host
	$visitor_email = $_POST["Email"];
	$visitor_name = $name[0] . " " . $name[1];
	//get host info
	$qryhost = "select FirstName, LastName, Email from Users where UserID = " . $pdo->quote($_POST["HostID"]);
	$rshost = $pdo->query($qryhost)->fetch();
	$host_email = $rshost[2];
	$host_name = $rshost[0] . " " . $rshost[1];
	$visitor_company = $_POST["CompanyName"];
	$sys_from_email = $_SESSION['ttsy'];
	$sys_from_name = $_SESSION['firstName'] . " " . $_SESSION['lastName'];
	if ($_POST["VisitStatus"] == 2) { // checked in
		$visit_about = $_POST["VisitAbout"];

		// @TODO Enable PHPMailer
		// include("visit_email.php");
	} else if ($_POST["VisitStatus"] == 3) { // checked out
		// checkout time : 24 hours format to 12 hours format 			
		// $chkotime = date("H:i", strtotime($_POST["CheckoutTime"]));	

		// @TODO Enable PHPMailer
		// include("host_email.php");
	}
}

if (!empty($_GET["vid"])) { // visitID
	// Display data
	$vData = DisplayDataValue($pdo, "Visits inner join Visitors on Visits.VisitorID = Visitors.VisitorID ", $_GET["vid"], "Visits.VisitID");
	// Edit visit is ONLY accessible on current day
	if ($vData[0]["VisitDate"] != date("Y-m-d")) {
		echo Redirection("admin.php?p=tsyazoovaina");
	}
	$stitle = "Edit a visit";
	if ((int) $vData[0]["VisitStatus"] === 3) {
		$where_status = " where VisitStatusID = 3 ";
	} else {
		$where_status = " where VisitStatusID IN (" . $pdo->quote($vData[0]["VisitStatus"] + 1) . " , " . $pdo->quote($vData[0]["VisitStatus"]) . ") ";
	}
	$vData[0]["VisitorName"] = $vData[0]["FirstName"] . " " . $vData[0]["LastName"];
	//echo "CTime:". $vData[0]["CheckoutTime"] . " | ". $vData[0]["CheckinTime"];
	if ($vData[0]["CheckoutTime"] === "00:00:00" || empty($vData[0]["CheckoutTime"])) {
		$checkoutTime = date('H:i', strtotime('+1 hour', strtotime($vData[0]["CheckinTime"])));
		$checkoutTime = date("g:i A", strtotime($checkoutTime));
	}
} else {
	$stitle = "Add a New Visit";
	$where_status = " where VisitStatusID <> 3"; //not checked out
	//echo "CTime:". $vData[0]["CheckoutTime"] . " | ". $vData[0]["CheckinTime"];
}
?>
<script>
	jQuery().ready(function ($) {
		// Inline validation for Visitor Name
		$('#VisitAbout').on('input blur', function () {
			if ($.trim($(this).val()) === "") {
				$('#visitAboutError').show();
				$(this).css({ "border-color": "#d9534f" });
			} else {
				$('#visitAboutError').hide();
				$(this).css({ "border-color": "#bdbdbd" });
			}
		});

		$('#VisitorName').on('input blur', function () {
			var name = $.trim($(this).val());
			if (name === "") {
				$('#visitorNameError').hide();
				return;
			}

			var names = name.split(" ");
			var hasSpace = name.indexOf(" ") !== -1;

			if (!hasSpace || names.length !== 2 || names[0] === "" || names[1] === "") {
				$('#visitorNameError').show();
				$(this).css({ "border-color": "#d9534f" });
			} else {
				$('#visitorNameError').hide();
				$(this).css({ "border-color": "#bdbdbd" });
			}
		});

		//only numbers
		<?php if (!empty($_GET["vid"])) { ?>
			$("#Email").prop('readonly', true);
			$("#EmpID").prop('readonly', true);
			$("#CompanyName").prop('readonly', true);
			$("#VisitorName").prop('readonly', true);
		<?php } ?>
		if ($("#VisitStatus").val() == 3) {
			$("#sec_chkotime").show();
			/*$("#CheckoutTime").val("<?php echo date('H', time()) . ":00:00"; ?>");*/
		<?php if (!empty($checkoutTime)) { ?>$("#CheckoutTime").val("<?php echo $checkoutTime; ?>"); <?php } ?>
		} else {
			$("#sec_chkotime").hide();
			$("#CheckoutTime").val("");
		}

		$("#VisitDate").datepicker({
			//changeMonth: true,
			//changeYear: true
			/*showButtonPanel: true, closeText: 'Clear Date and Close',
			  onClose: function (dateText, inst) {
				if ($(window.event.srcElement).hasClass('ui-datepicker-close'))
				  $("#visitDate").val('');
				}*/
			minDate: "0d",
			maxDate: "+1y +1w",
			onSelect: function () {
				//alert($(this).val());
				var cDate = "<?php echo date("m/d/Y"); ?>";
				//alert (cDate);
				if (cDate != $(this).val()) {
					// Populate Visit Status with "Expected" only
					//$("#VisitStatus option[value='2']").remove();
					//$("#VisitStatus option[value='1']").attr('selected', true);
					$('#VisitStatus')
						.find('option')
						.remove()
						.end()
						.append('<option value="1">Expected</option>')
						.val('1')
						;

				} else {
					$('#VisitStatus')
						.find('option')
						.remove()
						.end()
						.append('<option value="1">Expected</option><option value="2">Checked in</option>')
						;
				}
			}

		});

		/*$("#VisitDate").change({
			alert($(this).val());	
		});*/

		$("#VisitStatus").change(function () {
			if ($(this).val() == 3) {
				$("#sec_chkotime").show();
				/*$("#CheckoutTime").val("<?php echo date('H', time()) . ":00"; ?>");*/
				<?php if (!empty($checkoutTime)) { ?>$("#CheckoutTime").val("<?php echo $checkoutTime; ?>"); <?php } ?>
			} else {
				$("#sec_chkotime").hide();
				$("#CheckoutTime").val("");
			}
		});

		$("#submitdatav").click(function () {
			var err = 0, vn = 0, em = 0; var emailReg = /^([\w-\.]+@([\w-]+\.)+[\w-]{2,4})?$/; var msgerr = ""; var difftime = 0;
			//alert("bb");
			$('#Email').css({ "border-color": "#bdbdbd", "background-color": "#FFF" });
			$('#CheckoutTime').css({ "border-color": "#bdbdbd", "background-color": "#FFF" });
			$('#VisitorName').css({ "border-color": "#bdbdbd", "background-color": "#FFF" });
			$('#VisitAbout').css({ "border-color": "#bdbdbd", "background-color": "#FFF" });
			$('#visitAboutError').hide();

			if ($("#VisitStatus").val() == 3) {
				difftime = hmsToSeconds(timeAMPMTo24hrs($("#CheckoutTime").val())) - hmsToSeconds(timeAMPMTo24hrs($("#CheckinTime").val()));
				//alert(difftime);
			}

			if ($.trim($("#VisitStatus").val()) == "") { err = 1; $('#VisitStatus').css({ "border-color": "red", "background-color": "#F79BB7" }); } else { $('#VisitStatus').css({ "border-color": "#bdbdbd", "background-color": "#FFF" }); }
			if ($.trim($("#CenterID").val()) == "") { err = 1; $('#CenterID').css({ "border-color": "red", "background-color": "#F79BB7" }); } else { $('#CenterID').css({ "border-color": "#bdbdbd", "background-color": "#FFF" }); }
			if ($.trim($("#HostID").val()) == "") { err = 1; $('#HostID').css({ "border-color": "red", "background-color": "#F79BB7" }); } else { $('#HostID').css({ "border-color": "#bdbdbd", "background-color": "#FFF" }); }
			if ($.trim($("#VisitAbout").val()) == "") {
				err = 1;
				$('#VisitAbout').css({ "border-color": "red", "background-color": "#F79BB7" });
				$('#visitAboutError').show();
			} else {
				$('#VisitAbout').css({ "border-color": "#bdbdbd", "background-color": "#FFF" });
				$('#visitAboutError').hide();
			}

			var email1 = $.trim($("#Email").val());
			var empId = $.trim($("#EmpID").val());

			if (email1 === "" && empId === "") {
				err = 1;
				$("#Email, #EmpID").css({
					"border-color": "red",
					"background-color": "#F79BB7"
				});
			}

			if (email1 !== "" && !emailReg.test(email1)) {
				$("#Email").focus();
				em = 1;
				$("#Email").css({
					"border-color": "red",
					"background-color": "#F79BB7"
				});
			}
			// Visitor name
			var name = $.trim($("#VisitorName").val());
			//alert(name);
			if (name != "") {
				var names = name.split(" ");
				if (name.search(" ") == -1) { vn = 1; $('#VisitorName').css({ "border-color": "red", "background-color": "#F79BB7" }); }
				if (names.length != 2) { vn = 2; $('#VisitorName').css({ "border-color": "red", "background-color": "#F79BB7" }); }
			} else { err = 1; $('#VisitorName').css({ "border-color": "red", "background-color": "#F79BB7" }); }

			//alert(err + " | " + vn + " | " + em);

			if (err == 0 && vn == 0 && em == 0) {
				var data = {
					vn: $.trim($("#VisitorName").val()),
					em: $.trim($("#Email").val()),
					empid: $.trim($("#EmpID").val()),
					vid: $("#VisitorID").val(),
					vdate: $("#VisitDate").val(),
					visitid: $("#VisitID").val(),
					visitime: $("#CheckinTime").val(),
					centeridt: $("#CenterID").val()
				};

				jQuery.ajax({
					url: 'chkvisitdata.php',
					type: "POST",
					dataType: "html",
					data: data,
					async: false,
					success: function (msg) {
						//alert(msg);
						if (msg != "") {
							var res = msg.split("###"); var msgr = "";
							if (res[0] != "" && res[0] != "0") { /*alert(msg+": Misy1");*/ msgr = "No duplicate permitted. The entered email belongs to another visitor, please change the email field value. "; $('#Email').css({ "border-color": "red", "background-color": "#F79BB7" }); }
							if (res[1] != "" && res[1] != "0") { /*alert(msg+": Misy2");*/ msgr += "This visitor has not been checked out on the date selected."; }
							if (difftime < 0) { msgr += " Check out time should be greater than Check in time"; $('#CheckoutTime').css({ "border-color": "red", "background-color": "#F79BB7" }); }
							if (msgr != "") $("#errormsg").html("<center>" + msgr + "</center>");
							else { /*alert("subm: Tsisy");*/ $("#fvisit").submit(); }
						}
					}
				});


			} else {
				if (err == 1) msgerr = " Please fill the red (*) required fields.";
				if (em == 1) msgerr += " Email field is invalid.";
				if (vn == 1) msgerr += " Please verify the Visitor Name field if space separates the First and Last name.";
				else if (vn == 2) msgerr += " Visitor Name must contain two (2) words: First or Middle Name and Last Name.";
				$("#errormsg").html("<center>" + msgerr + "</center>");
			}


		});

		$("#VisitorName").autocomplete({
			source: "search_name.php",
			minLength: 2,
			select: function (event, ui) {
				//alert( "Selected: " + ui.item.value + " aka " + ui.item.id + "em: " + ui.item.email + " mob: " + ui.item.mobile);
				$("#VisitorID").val(ui.item.id);
				$("#Email").val(ui.item.email);
				$("#EmpID").val(ui.item.empid || "");
				$("#CompanyName").val(ui.item.coname);
				$("#Email").prop('readonly', true);
				$("#EmpID").prop('readonly', true);
				$("#CompanyName").prop('readonly', true);
				$("#VisitorName").prop('readonly', true);
			}
		})
			.autocomplete("instance")._renderItem = function (ul, item) {
				return $("<li>")
					/*.append( "<div>" + item.value + " - " + item.email + "</div>" )*/
					.append(
						"<div>" + item.value +
						" - <span class='text-bold'>" +
						(item.email ? item.email : item.empid) +
						"</span></div>"
					)
					.appendTo(ul);
			};

		$("#resetvisitor").click(function () {
			$("#VisitorID").val("");
			$("#VisitorName").val("");
			$("#Email").val("");
			$("#EmpID").val("");
			$("#CompanyName").val("");

			// Clear visit-related fields
			$("#HostID").val("");
			$("#VisitStatus").val("1");
			$("#VisitAbout").val("");
			$("#VisitComment").val("");
			$("#CheckoutTime").val("");
			$("#sec_chkotime").hide();

			// Keep today's date and current time
			$("#VisitDate").val("<?php echo date("m/d/Y"); ?>");
			$("#CheckinTime").val("<?php echo date('g:i A', time()); ?>");

			// Make fields editable again
			$("#Email").prop("readonly", false);
			$("#EmpID").prop("readonly", false);
			$("#CompanyName").prop("readonly", false);
			$("#VisitorName").prop("readonly", false);

			// Reset field styles
			$("#Email, #EmpID, #VisitorName, #CompanyName, #VisitAbout, #VisitComment, #HostID, #VisitStatus")
				.css({ "border-color": "#bdbdbd", "background-color": "#fff" });

			// Hide validation messages
			$("#visitorNameError").hide();
			$("#visitAboutError").hide();
			$("#errormsg").html("");

		});

		/*var anarana = '';
		$("#VisitorName").change(function( ) {					 
			alert ("a:" + anarana + " -- nval:" + $(this).val());
			if (anarana != $(this).val()) { 
				if (anarana!="") { $("#VisitorID").val(""); $("#Email").val(""); $("#Email").prop('disabled', false); $("#Mobile").prop('disabled', false);}
				anarana = $(this).val(); 
			}			
		}); */

	});

	function hmsToSeconds(s) {
		var b = s.split(':');
		return b[0] * 3600 + b[1] * 60 + (+b[2] || 0);
	}

	function timeAMPMTo24hrs(ora) {  // convert 12hrs AM/PM to 24 hrs time
		var time = ora;
		var hours = Number(time.match(/^(\d+)/)[1]);
		var minutes = Number(time.match(/:(\d+)/)[1]);
		var AMPM = time.match(/\s(.*)$/)[1];
		if (AMPM == "PM" && hours < 12) hours = hours + 12;
		if (AMPM == "AM" && hours == 12) hours = hours - 12;
		var sHours = hours.toString();
		var sMinutes = minutes.toString();
		if (hours < 10) sHours = "0" + sHours;
		if (minutes < 10) sMinutes = "0" + sMinutes;
		//alert(sHours + ":" + sMinutes);
		return (sHours + ":" + sMinutes + ":00");
	}

</script>
<link rel="stylesheet" href="assets/vendor/bootstrap-timepicker/css/bootstrap-timepicker.css" />
<section class="panel">
	<header class="panel-heading">

		<h2 class="panel-title"><?php echo $stitle; ?></h2>
	</header>
	<div class="panel-body">
		<div class="alert alert-info">
			<p class="m-none text-semibold h6">For an existing visitor, type 2 or more characters of his/her name in the
				"Visitor Name" field below, then <u><span class="text-danger">SELECT</span></u> it to display his/her
				existing info in the system.<br><br><span class="text-danger">Please separate Visitor First or Middle
					Name and Last Name with a space.</span></p>
		</div>
		<span class="text-danger">(*) Required fields</span><br>
		<span class="text-danger" id="errormsg"></span>
		<form name="fvisit" id="fvisit" method="post">
			<input type="hidden" name="VisitorID" id="VisitorID"
				value="<?php echo (empty($vData[0]["VisitorID"])) ? "" : $vData[0]["VisitorID"]; ?>" />
			<input type="hidden" name="VisitID" id="VisitID"
				value="<?php echo (empty($vData[0]["VisitID"])) ? "" : $vData[0]["VisitID"]; ?>" />
			<input type="hidden" name="VisitorType" id="VisitorType"
				value="<?php echo (empty($vData[0]["VisitorType"])) ? "GENERAL" : $vData[0]["VisitorType"]; ?>" />
			<div class="row">
				<div class="col-sm-6">
					<div class="form-group">
						<label class="col-md-3 control-label" for="inputDefault">Visitor Name <span
								class="required">*</span></label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="VisitorName" name="VisitorName"
								placeholder="First/Middle name  Last name"
								value="<?php echo (empty($vData[0]["VisitorName"])) ? "" : $vData[0]["VisitorName"]; ?>">
							<span class="help-block text-danger" id="visitorNameError"
								style="display:none; margin-top: 5px;">
								<i class="fa fa-exclamation-circle"></i> Must contain First and Last Name
							</span>
						</div>
					</div>
				</div>
				<div class="col-sm-6">
					<div class="form-group">
						<label class="col-md-3 control-label" for="inputDefault">Company Name </label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="CompanyName" name="CompanyName"
								value="<?php echo (empty($vData[0]["CompanyName"])) ? "" : $vData[0]["CompanyName"]; ?>">
						</div>
					</div>
				</div>
			</div><br>

			<div class="row">
				<div class="col-sm-6">
					<div class="form-group">
						<label class=" col-md-3 control-label">Email</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="Email" name="Email"
								value="<?php echo (empty($vData[0]["Email"])) ? "" : $vData[0]["Email"]; ?>">
						</div>
					</div>
				</div>
				<div class="col-sm-6">
					<div class="form-group">
						<label class=" col-md-3 control-label">Employee ID</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="EmpID" name="EmpID"
								value="<?php echo (empty($vData[0]["EmpID"])) ? "" : $vData[0]["EmpID"]; ?>">

							<small class="text-danger">
								* Email or Employee ID is required.
							</small>
						</div>
					</div>
				</div>
			</div><br>


			<div class="row">
				<div class="col-sm-6">
					<div class="form-group">
						<label class=" col-md-3 control-label">QS Center <span class="required">*</span></label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="CenterIDDisplay"
								value="<?php echo $_SESSION['center']; ?>" readonly
								style="background-color: #f5f5f5; cursor: not-allowed;">
							<input type="hidden" name="CenterID" id="CenterID"
								value="<?php echo $_SESSION['id_center']; ?>">
						</div>
					</div>
				</div>

			</div><br>

			<div class="row">
				<div class="col-sm-6">
					<div class="form-group">
						<label class=" col-md-3 control-label">Host <span class="required">*</span></label>
						<div class="col-md-6">
							<select class="input-sm" id="HostID" name="HostID">
								<?php echo ListUsers($pdo, (empty($vData[0]["HostID"])) ? 0 : $vData[0]["HostID"], " where Status = '1'"); ?>
							</select>
						</div>
					</div>
				</div>
			</div><br>

			<div class="row">
				<div class="col-sm-6">
					<div class="form-group">
						<label class=" col-md-3 control-label">Visit Status <span class="required">*</span></label>
						<div class="col-md-6">
							<select class="input-sm" id="VisitStatus" name="VisitStatus">
								<?php echo (!empty($where_status)) ? ListVisitStatus($pdo, (empty($vData[0]["VisitStatus"])) ? 1 : $vData[0]["VisitStatus"], $where_status) : "<option value='3'>Checked out</option>"; ?>
							</select>
						</div>
					</div>
				</div>

			</div><br>

			<div class="row">
				<div class="col-sm-6">
					<div class="form-group">
						<label class=" col-md-3 control-label">Visit Date <span class="required">*</span></label>
						<div class="col-md-6">
							<input type="text" class="inp-date" id="VisitDate" name="VisitDate"
								value="<?php echo date("m/d/Y"); ?>" readonly="true">
						</div>
					</div>
				</div>
				<!-- <div class="col-sm-6">
						<div class="form-group">
							<label class=" col-md-3 control-label">Check in time</label>
							<div class="col-md-4">
								<div class="input-group">								
								<?php /*echo SelectTimesAMPM("CheckinTime", (empty($vData[0]["CheckinTime"])) ? date('H', time()).":00:00" :  $vData[0]["CheckinTime"]);*/ ?>
								</div>
							</div>
						</div>
					</div> -->
				<div class="col-sm-6">
					<div class="form-group">
						<label class=" col-md-3 control-label">Check in time</label>
						<div class="col-md-4">
							<div class="input-group">
								<span class="input-group-addon">
									<i class="fa fa-clock-o"></i>
								</span>
								<input id="CheckinTime" name="CheckinTime" type="text" data-plugin-timepicker
									class="inp-tim"
									value="<?php echo (empty($vData[0]["CheckinTime"])) ? date('g:i A', time()) : date("g:i A", strtotime($vData[0]["CheckinTime"])); ?>"
									data-plugin-options='{ "minuteStep": 1 }' readonly="true">
							</div>
						</div>
					</div>
				</div>
			</div><br>
			<div class="row">
				<div class="col-sm-6">
					<div class="form-group">
						&nbsp;
					</div>
				</div>
				<div class="col-sm-6">
					<div class="form-group" id="sec_chkotime">
						<label class=" col-md-3 control-label">Check out time</label>
						<div class="col-md-4">
							<div class="input-group">
								<?php /*echo SelectTimesAMPM("CheckoutTime", (empty($vData[0]["CheckoutTime"])) ? date('H', time()).":00:00" : $vData[0]["CheckoutTime"]); */ ?>
								<span class="input-group-addon">
									<i class="fa fa-clock-o"></i>
								</span>
								<input id="CheckoutTime" name="CheckoutTime" type="text" data-plugin-timepicker
									class="inp-tim"
									value="<?php echo (empty($vData[0]["CheckoutTime"])) ? date('g:i A', time()) : date("g:i A", strtotime($vData[0]["CheckoutTime"])); ?>"
									data-plugin-options='{ "minuteStep": 1 }' readonly="true">
							</div>
						</div>
					</div>
				</div>
			</div><br>

			<div class="row">
				<div class="col-sm-6">
					<div class="form-group">
						<label class=" col-md-3 control-label">Visit Purpose <span class="required">*</span></label>
						<div class="col-md-6">
							<textarea class="form-control frmss" id="VisitAbout"
								name="VisitAbout"><?php echo (empty($vData[0]["VisitAbout"])) ? "" : $vData[0]["VisitAbout"]; ?></textarea>
							<span class="help-block text-danger" id="visitAboutError"
								style="display:none; margin-top: 5px;">
								<i class="fa fa-exclamation-circle"></i> Visit Purpose is required
							</span>
						</div>
					</div>
				</div>
				<div class="col-sm-6">
					<div class="form-group">
						<label class=" col-md-3 control-label">Comment</label>
						<div class="col-md-6">
							<textarea class="form-control frmss" id="VisitComment"
								name="VisitComment"><?php echo (empty($vData[0]["VisitComment"])) ? "" : $vData[0]["VisitComment"]; ?></textarea>
						</div>
					</div>
				</div>
			</div><br>

			<footer class="panel-footer">
				<center>
					<div style="
			display:flex;
			justify-content:center;
			align-items:center;
			gap:18px;
			flex-wrap:wrap;
			padding:10px 0;
		">

						<?php if (empty($_GET["vid"])) { ?>

							<button type="button" id="resetvisitor" name="resetvisitor" class="btn" style="
					background:#ffffff;
					color:#111;
					border:1px solid #d1d5db;
					padding:10px 24px;
					border-radius:10px;
					font-weight:600;
					font-size:14px;
					transition:all 0.2s ease;
					box-shadow:none;
				" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='#ffffff'">
								<i class="fa fa-refresh" style="margin-right:6px;"></i>
								Reset Visitor
							</button>

						<?php } ?>

						<button type="button" id="submitdatav" name="submitdatav" class="btn" style="
					background:linear-gradient(135deg,#1774DE,#0B5FC4);
					color:#fff;
					border:none;
					padding:10px 30px;
					border-radius:10px;
					font-weight:600;
					font-size:14px;
					transition:all 0.2s ease;
					box-shadow:none;
				" onmouseover="this.style.opacity='0.92'" onmouseout="this.style.opacity='1'">
							<i class="fa fa-check" style="margin-right:6px;"></i>
							Submit
						</button>

					</div>
				</center>
			</footer>

		</form>
	</div>
</section>