<?php
	Template::load('header');
	Template::load('acp_nav');
?>

<div class="row">
	<div class="col-lg-12">
		<div class="transparent_well col-md-12" style="padding-bottom: 20px;">
		<h2 class="drop_shadow clear-top"><?=_('Survey settings'); ?></h2>
	
		<form method="POST" action="<?php echo admin_study_url($study->name); ?>">
			<table class="table table-striped editstudies">
				<thead>
					<tr>
						<th>Option</th>
						<th>Value</th>
					</tr>
				</thead>
				<tbody>
		<?php
			foreach( $study->settings as $key => $value ):
				echo "<tr>";
				echo "<td>".h($key)."</td>";

				echo "<td><input class=\"form-control\" type=\"text\" size=\"50\" name=\"".h($key)."\" value=\"".h($value)."\"/></td>";
				echo "</tr>";
			endforeach;
		?>
				</tbody>
			</table>
			<div class="row col-md-4">
				<input type="submit" value="Save settings" class="btn">
			</div>
		</form>
		</div>
	</div>
</div>
<?php Template::load('footer');
