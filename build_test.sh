#!/bin/bash
# Simple build and push script

cd src
./mkpkg esphomepm
cd ..
git add .
git commit -m "test build"
git push
