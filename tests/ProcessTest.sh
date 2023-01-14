#!/bin/sh

for N in 1 2 3 ; do
  echo -n "${N}"
  
  for M in $(seq 1 $(((RANDOM%32)+1))) ; do
    echo -n " ${M}"
  done
  
  echo ""
  sleep 1
done
