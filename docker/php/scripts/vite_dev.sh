#!/bin/bash

scripts_folder=/usr/local/bin/scripts

bash "$scripts_folder"/wait_setup.sh

bash "$scripts_folder"/processes/run_dev_vite.sh
