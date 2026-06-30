<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
	include ("data_scripts/pdo_conn.php"); 
	include ("data_scripts/utils.php");
}
$daty = (!empty($_POST["daty"])) ? DateFormatDB($_POST["daty"]) : date("Y-m-d");
$orderBy = "CheckinTime";
if (!empty($_GET["act"])) { 
	$msgact = Array("New visit has been successfully added.", "Visit Info has been successfully updated.");
	$orderBy = "ModifDateTime";
}
?>
<section class="panel">
		<header class="panel-heading">
			
			<?php
			// visit total for the day: 
			$qrycount = "Select count(*) as nb from Visits v 
							inner join Visitors r on r.VisitorID = v.VisitorID left join Users u on u.UserID = v.HostID inner join VisitStatus vs on vs.VisitStatusID = v.VisitStatus where v.CenterID = ".$pdo->quote($_SESSION["id_center"]) . "
							and VisitDate = ". $pdo->quote($daty);
			$rscount = $pdo->query($qrycount)->fetch();				
			?>
			<h2 class="panel-title"><?php echo strtoupper($_SESSION["center"]); ?> - Total Number of visits for <span id="vtxtDate" class="text-tertiary"><?php echo (!empty($_POST["daty"])) ? $_POST["daty"] : date("m/d/Y");?></span> : <span id="num_visit"><?php echo $rscount[0];?></span></h2>
		</header>
		<?php  if (!empty($rscount[0])) { ?>
		<div class="panel-body">						
			<?php if ($daty == date("Y-m-d")) { ?>
			<span class="text-success" id="msg_update"><?php echo isset($msgact) ? $msgact[$_GET["act"]-1] : ''; ?></span><br>
			Select table row(s) below, then click on the following button to: <br>
			<input type="button" id="btncheckin" value="Check In" class="btn btn-success m-xs" data-toggle="tooltip" data-placement="top" title="Check in selected Visit(s)"/>&nbsp; &nbsp;
			<input type="button" id="btncheckout" value="Check Out" class="btn btn-info m-xs" data-toggle="tooltip" data-placement="top" title="Check out selected Visit(s)"/>&nbsp; &nbsp;
			<!-- <input type="button" id="btntablealls" value="Update All listed status(es)" class="btn btn-primary m-xs" data-toggle="tooltip" data-placement="top" title="Update All listed visits status(es)"/> &nbsp; &nbsp;  -->
			<!-- <input type="button" id="btntablealld" value="Delete All listed visit(s)" class="btn btn-primary m-xs" data-toggle="tooltip" data-placement="top" title="Delete All listed visit(s)"/>&nbsp; &nbsp; -->
			<input type="button" id="btntablesup" value="Delete selected Visit(s)" class="btn btn-warning m-xs" data-toggle="tooltip" data-placement="top" title="Delete selected Visit(s)"/>&nbsp; &nbsp;
			<input type="button" id="btntableunsel" value="Unselect Selected rows" class="btn m-xs" data-toggle="tooltip" data-placement="top" title="Unselect Selected rows" />&nbsp; &nbsp;
			<input type="button" id="btntableselall" value="Select all rows" class="btn btn-dark m-xs" data-toggle="tooltip" data-placement="top" title="Select all rows"/><br/><br>
			<?php }?>			
			
			<!-- <input type="hidden" id="dataids" /> -->
			<table class="table table-bordered table-striped mb-none" id="datatable-default">
				<thead>
					<tr>											
						<th class="colHidden" width="1">hidden</th>
						<th class="hidden-phone" style="width:70px">Status</th>
						<th>Visitor name</th>
						<th style="width:80px;">Check in time</th>
                        <th style="width:80px;">Check out time</th>
						<th class="colHidden" width="1">hidden</th>
						<th class="hidden-phone">Host</th>
						<!-- <th class="hidden-phone">Agreement</th> -->
						<th class="hidden-phone">QS Center Area</th>
						<th class="hidden-phone">Company</th>
						<th style="width:20px;">Edit</th>
						<th style="width:20px;">Delete</th>
						<th class="colHidden" width="1">hidden</th>	
						<th class="colHidden" width="1">hidden</th>	
					</tr>
				</thead>
				<tbody>
					<?php 
						$arCssStatus = array("text-primary", "text-secondary", "text-quartenary");
						$qrylist = "SELECT v.ModifDateTime, v.chkosystem, v.VisitID, CONCAT_WS(' ', r.FirstName, r.LastName) AS vname, vs.VisitStatus, v.VisitStatus AS visitStatusNum, v.VisitorType, TIME_FORMAT(CheckinTime, '%h:%i %p') AS CheckinTime2, CheckinTime, IF(v.CheckoutTime = '00:00:00', ' ', TIME_FORMAT(CheckoutTime, '%h:%i %p')) AS CheckoutTime, CONCAT_WS(' ', u.FirstName, u.LastName) AS hname, u.Email, qca.AreaName, AgreementSigned, CompanyName FROM Visits v INNER JOIN Visitors r ON r.VisitorID = v.VisitorID LEFT JOIN Users u ON u.UserID = v.HostID LEFT JOIN QSCenterArea qca ON qca.AreaID = v.AreaID INNER JOIN VisitStatus vs ON vs.VisitStatusID = v.VisitStatus 
							WHERE v.CenterID = " . $pdo->quote($_SESSION["id_center"]) . " AND VisitDate = " . $pdo->quote($daty) . " ORDER BY {$orderBy} DESC";
						$i=0;
						foreach ($pdo->query($qrylist) as $rowlist) { 
							$sysico = (!empty($rowlist["chkosystem"])) ? " <img src='assets/images/system-icon.png' title='system checkout'>" : "";
					?>
					<tr class="gradeX">
						<td class="colHidden" width="1"><?php echo $rowlist[$orderBy];?></td>	
						<td id="v_<?php echo $rowlist["VisitID"];?>" class="text-semibold <?php echo $arCssStatus[$rowlist["visitStatusNum"]-1];?>"><?php echo $rowlist["VisitStatus"]. $sysico;?></td>
						<td><?php echo $rowlist["vname"];?></td>
						<td class="center hidden-phone" style="width:80px;white-space:nowrap;">
                        <?php echo $rowlist["CheckinTime2"];?></td>

                        <td id="t_<?php echo $rowlist["VisitID"];?>" class="center hidden-phone" style="width:80px;white-space:nowrap;"><?php echo $rowlist["CheckoutTime"];?></td>

                        <td class="colHidden" width="1"><?php echo $rowlist["CheckoutTime"];?></td>
						<td><?php echo $rowlist["hname"];?></td>
						<?php // <td><?php echo !empty($rowlist["AgreementSigned"]) ? "Yes" : "No" ;?></td>
						<td><?php echo $rowlist["AreaName"]; ?></td>
						<td><?php echo $rowlist["CompanyName"];?></td>
						<td class="center" style="width:40px;">
							<a href="admin.php?p=add_visit&vid=<?php echo $rowlist["VisitID"];?>" class="on-default edit-row"><i class="fa fa-pencil"></i></a>
						</td>
						<td class="center" style="width:40px;">
							<a href="#" class="on-default remove-row"><i class="text-warning fa fa-trash-o"></i><input type="hidden" id="id_<?php echo $rowlist["VisitID"];?>" value="%%<?php echo $rowlist["VisitID"];?>%%"></a>
						</td>
						<td class="colHidden" width="1"><?php echo $rowlist["VisitID"];?></td>
						<td class="colHidden" width="1"><?php echo $rowlist["Email"];?></td>	
					</tr>
					<?php } ?>

				</tbody>
			</table>								
		</div>
		<?php } ?>
</section>

<script>
		$(document).ready(function() {
			//auto refresh every 10 seconds - removed for the moment
			//setTimeout(function(){
			//   location.reload();
		    //},10000);	
			
			<?php if ($daty == date("Y-m-d")) { ?>			
			var table = $('#datatable-default').DataTable({ select: true, "order": [[ 0, "desc" ]]});	
			//var table = $('#datatable-default').DataTable();	
				
			$('#datatable-default tbody').on( 'click', 'tr', function () {										
				$(this).toggleClass('selected');
				var nbsel = table.rows('.selected').data().length;
				if (nbsel == 0) $("#datatable-default_paginate").show(); 
				else $("#datatable-default_paginate").hide();				
			} );
			
			$('#datatable-default tbody').on( 'click', 'td', function () {
				//alert( 'Clicked on cell in visible column: '+table.cell( this ).index().columnVisible );
				var rowIdx = table.cell( this ).index().row; //index of row selected
				//alert(rowIdx); 			
				if (table.cell( this ).index().columnVisible == 10) {
					//alert( table.cell( this ).data() );
					var idcell = table.cell( this ).data().split("%%");
					//alert("ID_"+idcell[1]);
					if (confirm("Are you sure to remove this visit?")) {	
						// ajax
						var data = { 
							idvisit : idcell[1]						
						};		
						jQuery.ajax({
						  url: 'visit_vono.php',
						  type: "POST",
						  dataType: "html",
						  data:data,
						  async: false,	
						  success: function(msg) {							
								//alert(msg);	
								$("#msg_update").html(msg);									
								table.row(rowIdx).remove().draw();
							}
						});
					}
				}
			} );
			
			$("#btntableunsel").click( function () {
				$('#datatable-default tbody tr').removeClass('selected');
				$("#datatable-default_paginate").show();
				//location.reload();
			} );
			
			$("#btntableselall").click( function () {
				$('#datatable-default tbody tr').addClass('selected');
				$("#datatable-default_paginate").hide();					
			} );
		 
			
			$('#btncheckin').click( function () {
				var nbsel = table.rows('.selected').data().length;
				if (nbsel == 0) {
					$("#msg_update").html("Please select at least one visit to check in.");
					return;
				}
				
				if (confirm("Are you sure to check in " + nbsel + " visit(s) selected?")) {
					var rows = table.rows('.selected').data().toArray();
					var status = "Checked in";
					var css_status = "<span class='text-semibold text-secondary'>" + status + "</span>";
					
					// ajax
					var data = {
						idst : rows,
						action: 'checkin'
					};
					jQuery.ajax({
					  url: 'visit_status.php',
					  type: "POST",
					  dataType: "html",
					  data:data,
					  async: false,
					  success: function(msg) {
							$("#msg_update").html(msg);
							
							// Update UI for checked in visits
							for (var i=0; i< nbsel; i++) {
								if (rows[i][1] != "Checked in") {
									$("#v_"+rows[i][11]).html(css_status);
									rows[i][1] = status;
								}
							}
							// Deselect all rows after check-in
							$('#btntableunsel').trigger('click');
						}
					});
				}
			} );
			
			$('#btncheckout').click( function () {
				var nbsel = table.rows('.selected').data().length;
				if (nbsel == 0) {
					$("#msg_update").html("Please select at least one visit to check out.");
					return;
				}
				
				if (confirm("Are you sure to check out " + nbsel + " visit(s) selected?")) {
					var rows = table.rows('.selected').data().toArray();
					var status = "Checked out";
					var css_status = "<span class='text-semibold text-quartenary'>" + status + "</span>";
					
					// ajax
					var data = {
						idst : rows,
						action: 'checkout'
					};
					jQuery.ajax({
					  url: 'visit_status.php',
					  type: "POST",
					  dataType: "html",
					  data:data,
					  async: false,
					  success: function(msg) {
							$("#msg_update").html(msg);
							
							// Update UI for checked out visits
							var now = new Date();
							var h = now.getHours();
							var m = now.getMinutes();
							var ampm = h >= 12 ? "PM" : "AM";
							h = h % 12 || 12;
							var hStr = (h < 10 ? "0" : "") + h;
							var mStr = (m < 10 ? "0" : "") + m;
							var currentTime = hStr + ":" + mStr + " " + ampm;

							for (var i=0; i< nbsel; i++) {
								if (rows[i][1] == "Checked in") {
									$("#t_"+rows[i][11]).html(currentTime);
									$("#v_"+rows[i][11]).html(css_status);
									rows[i][1] = status;
								}
							}
							// Deselect all rows after check-out
							$('#btntableunsel').trigger('click');
						}
					});
				}
			} );
			
			$('#btntablesup').click( function () {
				var nbsel = table.rows('.selected').data().length;
				if (confirm("Are you sure to remove " + nbsel + " visit(s) selected?")) {										
					var rows = table.rows('.selected').data().toArray();
					//alert(JSON.stringify(rows, null, 4));
					
					// ajax
					var data = { 
						idst : rows						
					};		
					jQuery.ajax({
					  url: 'visit_vono_all.php',
					  type: "POST",
					  dataType: "html",
					  data:data,
					  async: false,	
					  success: function(msg) {							
							//alert(msg);	
							$("#msg_update").html(msg);
							$("#num_visit").html($('#num_visit').html() - nbsel);	
							table.rows('.selected').remove().draw();
							$("#datatable-default_paginate").show();	
						}
					});
					
					/*for (var i=0; i< nbsel; i++) {
						if (rows[i][1] == "Expected") { 
							status = "Checked in"; css_status="<span class='text-semibold text-secondary'>" + status + "</span>";
						} else { status = "Checked out"; css_status="<span class='text-semibold text-quartenary'>" + status + "</span>";}							
						$("#v_"+rows[i][11]).html(css_status);							
						rows[i][1] = status;
						//alert("#st_"+rows[i][10] + ":" + status);
					}	*/

				}
				
				
			} );
			/*$('#datatable-default tbody tr td a').on( 'click', 'i.fa-trash-o', function () {
				var rows = table.rows('.selected').data().toArray();
				alert(rows[0][10]);
			});*/
			
			
			<?php }  else {?> var table = $('#datatable-default').DataTable({ select: false, "order": [[ 0, "desc" ]]}); <?php } ?>
		} );
</script>