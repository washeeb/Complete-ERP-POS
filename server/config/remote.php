<?php

/*
 * The remote support relay (MeshCentral, installed with installer/remote-relay/install-relay.sh on any VPS — chapter 20).
 * Empty = no relay yet: the View screen / Take control buttons do not show, everything else works as before.
 */
return [
    'relay_url' => rtrim((string) env('REMOTE_RELAY_URL', ''), '/'),
];
